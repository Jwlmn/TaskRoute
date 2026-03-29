<?php

namespace Tests\Feature;

use App\Models\DispatchTask;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchTaskPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_only_see_own_tasks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $driverA = User::query()->where('account', 'driver')->firstOrFail();
        $driverB = User::query()->where('account', 'driver2')->firstOrFail();

        DispatchTask::query()->create([
            'task_no' => 'DT-TEST-OWN',
            'driver_id' => $driverA->id,
            'status' => 'assigned',
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-TEST-OTHER',
            'driver_id' => $driverB->id,
            'status' => 'assigned',
        ]);

        Sanctum::actingAs($driverA);
        $response = $this->postJson('/api/v1/dispatch-task/list', []);

        $response->assertOk();
        $taskNos = collect($response->json('data'))->pluck('task_no')->all();
        $this->assertContains('DT-TEST-OWN', $taskNos);
        $this->assertNotContains('DT-TEST-OTHER', $taskNos);
    }

    public function test_customer_cannot_list_dispatch_tasks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/dispatch-task/list', [])->assertStatus(403);
    }

    public function test_dispatcher_can_query_task_order_list_with_filters(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('role', 'driver')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $task = DispatchTask::query()->create([
            'task_no' => 'DT-ORDER-LIST-001',
            'driver_id' => $driver->id,
            'status' => 'assigned',
        ]);

        $pendingOrder = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();
        $completedOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-DT-LIST-COMPLETED',
            'cargo_category_id' => $pendingOrder->cargo_category_id,
            'submitter_id' => $pendingOrder->submitter_id,
            'client_name' => $pendingOrder->client_name,
            'pickup_address' => $pendingOrder->pickup_address,
            'dropoff_address' => $pendingOrder->dropoff_address,
            'audit_status' => 'approved',
            'status' => 'completed',
        ]);

        $task->orders()->sync([
            $pendingOrder->id => ['sequence' => 1],
            $completedOrder->id => ['sequence' => 2],
        ]);

        $this->postJson('/api/v1/dispatch-task/order-list', [
            'task_id' => $task->id,
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.order_no', 'PO-DT-LIST-COMPLETED');
    }

    public function test_dispatch_task_list_pagination_respects_data_scope(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteIn = LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteOut = LogisticsSite::query()
            ->where('id', '!=', $siteIn->id)
            ->orderBy('id')
            ->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteIn->id]],
        ])->save();

        $vehicleIn = Vehicle::query()->create([
            'plate_number' => '沪P-SCOPE-IN-01',
            'name' => '范围内车辆',
            'vehicle_type' => 'truck',
            'site_id' => (int) $siteIn->id,
            'status' => 'idle',
        ]);
        $vehicleOut = Vehicle::query()->create([
            'plate_number' => '沪P-SCOPE-OUT-01',
            'name' => '范围外车辆',
            'vehicle_type' => 'truck',
            'site_id' => (int) $siteOut->id,
            'status' => 'idle',
        ]);

        foreach (range(1, 25) as $index) {
            DispatchTask::query()->create([
                'task_no' => sprintf('DT-SCOPE-IN-%03d', $index),
                'vehicle_id' => $vehicleIn->id,
                'status' => 'assigned',
                'dispatch_mode' => 'single_vehicle_single_order',
            ]);
        }
        foreach (range(1, 10) as $index) {
            DispatchTask::query()->create([
                'task_no' => sprintf('DT-SCOPE-OUT-%03d', $index),
                'vehicle_id' => $vehicleOut->id,
                'status' => 'assigned',
                'dispatch_mode' => 'single_vehicle_single_order',
            ]);
        }

        Sanctum::actingAs($dispatcher);
        $response = $this->postJson('/api/v1/dispatch-task/list?page=2', [])->assertOk();

        $taskNos = collect($response->json('data'))->pluck('task_no')->values()->all();
        $this->assertCount(5, $taskNos);
        $this->assertTrue(collect($taskNos)->every(fn ($taskNo) => str_starts_with((string) $taskNo, 'DT-SCOPE-IN-')));
    }

    public function test_dispatch_task_list_can_filter_by_status_group(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        DispatchTask::query()->create([
            'task_no' => 'DT-FILTER-ASSIGNED',
            'status' => 'assigned',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-FILTER-ACCEPTED',
            'status' => 'accepted',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-FILTER-INPROGRESS',
            'status' => 'in_progress',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-FILTER-COMPLETED',
            'status' => 'completed',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-FILTER-CANCELLED',
            'status' => 'cancelled',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);

        $response = $this->postJson('/api/v1/dispatch-task/list', [
            'status_group' => 'in_progress',
        ])->assertOk();

        $taskNos = collect($response->json('data'))->pluck('task_no')->all();
        $this->assertContains('DT-FILTER-ACCEPTED', $taskNos);
        $this->assertContains('DT-FILTER-INPROGRESS', $taskNos);
        $this->assertNotContains('DT-FILTER-ASSIGNED', $taskNos);
        $this->assertNotContains('DT-FILTER-COMPLETED', $taskNos);
        $this->assertNotContains('DT-FILTER-CANCELLED', $taskNos);
    }

    public function test_dispatch_task_list_can_filter_by_keyword_for_task_vehicle_and_driver(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::factory()->create([
            'account' => 'keyword-driver',
            'name' => '关键司机',
            'role' => 'driver',
        ]);
        $site = LogisticsSite::query()->orderBy('id')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $matchVehicle = Vehicle::query()->create([
            'plate_number' => '沪A-KEY-001',
            'name' => '关键字车辆',
            'vehicle_type' => 'truck',
            'site_id' => (int) $site->id,
            'driver_id' => $driver->id,
            'status' => 'idle',
        ]);
        $otherVehicle = Vehicle::query()->create([
            'plate_number' => '沪A-OTHER-001',
            'name' => '普通车辆',
            'vehicle_type' => 'truck',
            'site_id' => (int) $site->id,
            'status' => 'idle',
        ]);

        DispatchTask::query()->create([
            'task_no' => 'DT-KEYWORD-HIT',
            'vehicle_id' => $matchVehicle->id,
            'driver_id' => $driver->id,
            'status' => 'assigned',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-KEYWORD-MISS',
            'vehicle_id' => $otherVehicle->id,
            'status' => 'assigned',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);

        $this->postJson('/api/v1/dispatch-task/list', [
            'keyword' => 'KEYWORD-HIT',
        ])->assertOk()
            ->assertJsonPath('data.0.task_no', 'DT-KEYWORD-HIT');

        $vehicleResponse = $this->postJson('/api/v1/dispatch-task/list', [
            'keyword' => '关键字车辆',
        ])->assertOk();
        $vehicleTaskNos = collect($vehicleResponse->json('data'))->pluck('task_no')->all();
        $this->assertContains('DT-KEYWORD-HIT', $vehicleTaskNos);
        $this->assertNotContains('DT-KEYWORD-MISS', $vehicleTaskNos);

        $driverResponse = $this->postJson('/api/v1/dispatch-task/list', [
            'keyword' => $driver->name,
        ])->assertOk();
        $driverTaskNos = collect($driverResponse->json('data'))->pluck('task_no')->all();
        $this->assertContains('DT-KEYWORD-HIT', $driverTaskNos);
    }
}
