<?php

namespace Tests\Feature;

use App\Models\DispatchTask;
use App\Models\PrePlanOrder;
use App\Models\User;
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
}
