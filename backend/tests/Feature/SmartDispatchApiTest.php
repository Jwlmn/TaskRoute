<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\DispatchTask;
use App\Models\PrePlanOrder;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartDispatchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_can_preview_dispatch_result(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/dispatch/preview');

        $response->assertOk()
            ->assertJsonStructure([
                'assignments',
                'unassigned',
            ]);
    }

    public function test_preview_respects_vehicle_compartment_constraints(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $diesel = CargoCategory::query()->where('code', 'diesel')->firstOrFail();

        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪Z90001',
            'name' => '测试分仓罐车',
            'vehicle_type' => 'tank',
            'driver_id' => User::query()->where('role', 'driver')->value('id'),
            'max_weight_kg' => 20000,
            'max_volume_m3' => 30,
            'status' => 'idle',
            'meta' => [
                'compartment_enabled' => true,
                'compartments' => [
                    ['no' => 1, 'capacity_m3' => 8, 'allowed_cargo_category_ids' => [$gasoline->id]],
                ],
            ],
        ]);

        $orderFit = PrePlanOrder::query()->create([
            'order_no' => 'PO-TEST-CPT-1',
            'cargo_category_id' => $gasoline->id,
            'client_name' => '测试客户A',
            'pickup_address' => '测试装货地A',
            'dropoff_address' => '测试卸货地A',
            'cargo_weight_kg' => 1000,
            'cargo_volume_m3' => 5,
            'expected_pickup_at' => now()->addHour(),
            'expected_delivery_at' => now()->addHours(2),
            'status' => 'pending',
        ]);
        $orderOverflow = PrePlanOrder::query()->create([
            'order_no' => 'PO-TEST-CPT-2',
            'cargo_category_id' => $gasoline->id,
            'client_name' => '测试客户B',
            'pickup_address' => '测试装货地B',
            'dropoff_address' => '测试卸货地B',
            'cargo_weight_kg' => 1000,
            'cargo_volume_m3' => 5,
            'expected_pickup_at' => now()->addHours(2),
            'expected_delivery_at' => now()->addHours(3),
            'status' => 'pending',
        ]);
        $orderNotAllowed = PrePlanOrder::query()->create([
            'order_no' => 'PO-TEST-CPT-3',
            'cargo_category_id' => $diesel->id,
            'client_name' => '测试客户C',
            'pickup_address' => '测试装货地C',
            'dropoff_address' => '测试卸货地C',
            'cargo_weight_kg' => 1000,
            'cargo_volume_m3' => 2,
            'expected_pickup_at' => now()->addHours(3),
            'expected_delivery_at' => now()->addHours(4),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/v1/dispatch/preview', [
            'vehicle_ids' => [$vehicle->id],
            'order_ids' => [$orderFit->id, $orderOverflow->id, $orderNotAllowed->id],
        ]);

        $response->assertOk();
        $this->assertSame([$orderFit->id], $response->json('assignments.0.order_ids'));
        $this->assertCount(2, $response->json('unassigned'));
        $this->assertSame($orderOverflow->id, $response->json('unassigned.0.order_id'));
        $this->assertSame($orderNotAllowed->id, $response->json('unassigned.1.order_id'));
        $this->assertSame($orderFit->id, $response->json('assignments.0.compartment_plan.0.order_id'));
        $this->assertSame(1, $response->json('assignments.0.compartment_plan.0.compartment_no'));
    }

    public function test_preview_uses_amap_route_when_enabled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        Config::set('services.amap.web_key', 'test-key');
        Config::set('services.amap.enable_in_testing', true);
        Http::fake([
            'https://restapi.amap.com/v3/geocode/geo*' => function ($request) {
                $address = (string) $request->data()['address'];
                $map = [
                    '上海油库A' => '121.480000,31.220000',
                    '上海加油站B' => '121.520000,31.240000',
                    '上海冷链仓C' => '121.450000,31.210000',
                    '上海商超门店D' => '121.430000,31.260000',
                ];

                return Http::response([
                    'status' => '1',
                    'geocodes' => [
                        ['location' => $map[$address] ?? '121.473701,31.230416'],
                    ],
                ], 200);
            },
            'https://restapi.amap.com/v3/direction/driving*' => Http::response([
                'status' => '1',
                'route' => [
                    'paths' => [
                        ['distance' => '20000', 'duration' => '2400', 'tolls' => '20'],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/dispatch/preview');

        $response->assertOk()
            ->assertJsonPath('assignments.0.optimizer', 'amap')
            ->assertJsonPath('assignments.0.estimated_distance_km', 20)
            ->assertJsonPath('assignments.0.estimated_duration_min', 40)
            ->assertJsonPath('assignments.0.route_meta.optimizer', 'amap');
    }

    public function test_dispatcher_can_manual_adjust_and_create_tasks(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $vehicle = Vehicle::query()->where('status', 'idle')->firstOrFail();
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $orders = PrePlanOrder::query()
            ->whereIn('status', ['pending', 'scheduled'])
            ->where('cargo_category_id', $gasoline->id)
            ->limit(2)
            ->get();
        $this->assertCount(2, $orders);

        $response = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$orders[1]->id, $orders[0]->id],
                    'estimated_distance_km' => 22.5,
                    'estimated_fuel_l' => 12.8,
                    'estimated_duration_min' => 48,
                    'route_meta' => [
                        'optimizer' => 'amap',
                        'strategy' => 'manual_adjusted',
                    ],
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonCount(1, 'created_task_ids');

        $taskId = (int) $response->json('created_task_ids.0');
        $task = DispatchTask::query()->findOrFail($taskId);
        $this->assertSame($vehicle->id, (int) $task->vehicle_id);
        $this->assertSame((int) $vehicle->driver_id, (int) $task->driver_id);
        $this->assertSame('single_vehicle_multi_order', $task->dispatch_mode);
        $this->assertSame(true, (bool) ($task->route_meta['manual_adjusted'] ?? false));

        $this->assertDatabaseHas('dispatch_task_orders', [
            'dispatch_task_id' => $taskId,
            'pre_plan_order_id' => $orders[1]->id,
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('dispatch_task_orders', [
            'dispatch_task_id' => $taskId,
            'pre_plan_order_id' => $orders[0]->id,
            'sequence' => 2,
        ]);
    }
}
