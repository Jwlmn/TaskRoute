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

    private function resolveUnboundDriverId(): int
    {
        $driverId = (int) User::query()
            ->where('role', 'driver')
            ->where('status', 'active')
            ->whereNotIn('id', Vehicle::query()->whereNotNull('driver_id')->pluck('driver_id'))
            ->value('id');

        if ($driverId > 0) {
            return $driverId;
        }

        return (int) User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ])->id;
    }

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
            'driver_id' => $this->resolveUnboundDriverId(),
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

    public function test_compartment_vehicle_cannot_carry_more_orders_than_compartments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪Z81234',
            'name' => '双仓油罐车',
            'vehicle_type' => 'tank',
            'driver_id' => $this->resolveUnboundDriverId(),
            'max_weight_kg' => 26000,
            'max_volume_m3' => 40,
            'status' => 'idle',
            'meta' => [
                'compartment_enabled' => true,
                'compartments' => [
                    ['no' => 1, 'capacity_m3' => 20, 'allowed_cargo_category_ids' => [$gasoline->id]],
                    ['no' => 2, 'capacity_m3' => 20, 'allowed_cargo_category_ids' => [$gasoline->id]],
                ],
            ],
        ]);

        $orders = collect([1, 2, 3])->map(function ($index) use ($gasoline) {
            return PrePlanOrder::query()->create([
                'order_no' => "PO-TEST-SLOT-{$index}",
                'cargo_category_id' => $gasoline->id,
                'client_name' => "测试客户{$index}",
                'pickup_address' => "测试装货地{$index}",
                'dropoff_address' => "测试卸货地{$index}",
                'cargo_weight_kg' => 800,
                'cargo_volume_m3' => 2.5,
                'expected_pickup_at' => now()->addHours($index),
                'expected_delivery_at' => now()->addHours($index + 1),
                'status' => 'pending',
            ]);
        });

        $response = $this->postJson('/api/v1/dispatch/preview', [
            'vehicle_ids' => [$vehicle->id],
            'order_ids' => $orders->pluck('id')->all(),
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('assignments.0.order_ids'));
        $this->assertCount(1, $response->json('unassigned'));
    }

    public function test_preview_uses_amap_route_when_enabled(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $siteId = (int) \App\Models\LogisticsSite::query()->where('name', '上海油库A')->value('id');

        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪ZAMAP1',
            'name' => '高德路线测试车',
            'vehicle_type' => 'truck',
            'site_id' => $siteId > 0 ? $siteId : null,
            'driver_id' => $this->resolveUnboundDriverId(),
            'max_weight_kg' => 12000,
            'max_volume_m3' => 20,
            'status' => 'idle',
        ]);
        $order = PrePlanOrder::query()->create([
            'order_no' => 'PO-TEST-AMAP-1',
            'cargo_category_id' => $gasoline->id,
            'client_name' => '高德路线测试客户',
            'pickup_site_id' => $siteId > 0 ? $siteId : null,
            'pickup_address' => '上海油库A',
            'dropoff_site_id' => (int) \App\Models\LogisticsSite::query()->where('name', '上海加油站B')->value('id') ?: null,
            'dropoff_address' => '上海加油站B',
            'cargo_weight_kg' => 1000,
            'cargo_volume_m3' => 5,
            'expected_pickup_at' => now()->addHour(),
            'expected_delivery_at' => now()->addHours(2),
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

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

        $response = $this->postJson('/api/v1/dispatch/preview', [
            'vehicle_ids' => [$vehicle->id],
            'order_ids' => [$order->id],
        ]);

        $response->assertOk()
            ->assertJsonPath('assignments.0.optimizer', 'amap')
            ->assertJsonPath('assignments.0.estimated_distance_km', 20)
            ->assertJsonPath('assignments.0.estimated_duration_min', 40)
            ->assertJsonPath('assignments.0.route_meta.optimizer', 'amap');
    }

    public function test_non_compartment_vehicle_can_only_match_single_order(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $driverId = User::query()->where('role', 'driver')->value('id');
        if (! $driverId || Vehicle::query()->where('driver_id', $driverId)->exists()) {
            $driverId = $this->resolveUnboundDriverId();
        }
        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪Z93001',
            'name' => '无分仓测试车',
            'vehicle_type' => 'truck',
            'driver_id' => $driverId,
            'max_weight_kg' => 26000,
            'max_volume_m3' => 60,
            'status' => 'idle',
            'meta' => ['compartment_enabled' => false, 'compartments' => []],
        ]);

        $orders = collect([1, 2, 3])->map(function ($index) use ($gasoline) {
            return PrePlanOrder::query()->create([
                'order_no' => "PO-TEST-NC-{$index}",
                'cargo_category_id' => $gasoline->id,
                'client_name' => "测试客户{$index}",
                'pickup_address' => "测试装货地{$index}",
                'dropoff_address' => "测试卸货地{$index}",
                'cargo_weight_kg' => 500,
                'cargo_volume_m3' => 1.5,
                'expected_pickup_at' => now()->addHours($index),
                'expected_delivery_at' => now()->addHours($index + 1),
                'status' => 'pending',
            ]);
        });

        $response = $this->postJson('/api/v1/dispatch/preview', [
            'vehicle_ids' => [$vehicle->id],
            'order_ids' => $orders->pluck('id')->all(),
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('assignments.0.order_ids'));
        $this->assertCount(2, $response->json('unassigned'));
    }

    public function test_dispatcher_can_manual_adjust_and_create_tasks(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪Z92001',
            'name' => '手工下发分仓车',
            'vehicle_type' => 'tank',
            'driver_id' => $this->resolveUnboundDriverId(),
            'max_weight_kg' => 26000,
            'max_volume_m3' => 40,
            'status' => 'idle',
            'meta' => [
                'compartment_enabled' => true,
                'compartments' => [
                    ['no' => 1, 'capacity_m3' => 20],
                    ['no' => 2, 'capacity_m3' => 20],
                ],
            ],
        ]);
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

    public function test_manual_create_rejects_multi_order_for_non_compartment_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $driverId = $this->resolveUnboundDriverId();
        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪Z92002',
            'name' => '无分仓手工校验车',
            'vehicle_type' => 'truck',
            'driver_id' => $driverId,
            'max_weight_kg' => 18000,
            'max_volume_m3' => 30,
            'status' => 'idle',
            'meta' => ['compartment_enabled' => false, 'compartments' => []],
        ]);
        $orders = PrePlanOrder::query()
            ->whereIn('status', ['pending', 'scheduled'])
            ->limit(2)
            ->get();
        $this->assertCount(2, $orders);

        $response = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$orders[0]->id, $orders[1]->id],
                ],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', '未启用分仓的车辆不可拼单，请改为单车单订单');
    }

    public function test_dispatch_preview_rejects_out_of_scope_order_or_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteA = \App\Models\LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteB = \App\Models\LogisticsSite::query()->where('id', '!=', $siteA->id)->orderBy('id')->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteA->id]],
        ])->save();

        Sanctum::actingAs($dispatcher);

        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪Z-SCOPE-001',
            'name' => '越权范围车辆',
            'vehicle_type' => 'truck',
            'site_id' => (int) $siteB->id,
            'driver_id' => $this->resolveUnboundDriverId(),
            'max_weight_kg' => 8000,
            'max_volume_m3' => 18,
            'status' => 'idle',
        ]);

        $categoryId = (int) CargoCategory::query()->value('id');
        $order = PrePlanOrder::query()->create([
            'order_no' => 'PO-SCOPE-OUT-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围外客户',
            'pickup_site_id' => (int) $siteB->id,
            'pickup_address' => '范围外装货地',
            'dropoff_site_id' => (int) $siteB->id,
            'dropoff_address' => '范围外卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

        $this->postJson('/api/v1/dispatch/preview', [
            'order_ids' => [$order->id],
            'vehicle_ids' => [$vehicle->id],
        ])->assertStatus(403)
            ->assertJsonPath('message', '包含超出当前账号数据范围的预计划单');
    }

    public function test_dispatch_manual_create_rejects_out_of_scope_ids(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteA = \App\Models\LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteB = \App\Models\LogisticsSite::query()->where('id', '!=', $siteA->id)->orderBy('id')->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteA->id]],
        ])->save();

        Sanctum::actingAs($dispatcher);

        $vehicle = Vehicle::query()->create([
            'plate_number' => '沪Z-SCOPE-002',
            'name' => '越权范围车辆2',
            'vehicle_type' => 'truck',
            'site_id' => (int) $siteB->id,
            'driver_id' => $this->resolveUnboundDriverId(),
            'max_weight_kg' => 8000,
            'max_volume_m3' => 18,
            'status' => 'idle',
        ]);

        $categoryId = (int) CargoCategory::query()->value('id');
        $order = PrePlanOrder::query()->create([
            'order_no' => 'PO-SCOPE-OUT-002',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围外客户2',
            'pickup_site_id' => (int) $siteB->id,
            'pickup_address' => '范围外装货地2',
            'dropoff_site_id' => (int) $siteB->id,
            'dropoff_address' => '范围外卸货地2',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

        $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => (int) $vehicle->id,
                    'order_ids' => [(int) $order->id],
                ],
            ],
        ])->assertStatus(403)
            ->assertJsonPath('message', '包含超出当前账号数据范围的车辆');
    }
}
