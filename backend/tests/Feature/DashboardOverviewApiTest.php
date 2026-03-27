<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\DispatchTask;
use App\Models\DriverLocation;
use App\Models\PrePlanOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_can_get_dashboard_overview_metrics(): void
    {
        $dispatcher = User::factory()->create([
            'role' => 'dispatcher',
            'status' => 'active',
        ]);
        $driverA = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);
        $driverB = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);

        $cargo = CargoCategory::query()->create([
            'name' => '汽油',
            'code' => 'gasoline-test',
        ]);

        PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-001',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户A',
            'pickup_address' => '上海A',
            'dropoff_address' => '上海B',
            'status' => 'pending',
        ]);
        PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-002',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户B',
            'pickup_address' => '上海C',
            'dropoff_address' => '上海D',
            'status' => 'pending',
        ]);

        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-001',
            'driver_id' => $driverA->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-002',
            'driver_id' => $driverA->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-003',
            'driver_id' => $driverB->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-004',
            'driver_id' => $driverB->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'cancelled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-005',
            'driver_id' => $driverA->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'completed',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DriverLocation::query()->create([
            'driver_id' => $driverA->id,
            'dispatch_task_id' => null,
            'lng' => 121.4737010,
            'lat' => 31.2304160,
            'located_at' => now()->subMinutes(5),
        ]);
        DriverLocation::query()->create([
            'driver_id' => $driverB->id,
            'dispatch_task_id' => null,
            'lng' => 121.4737010,
            'lat' => 31.2304160,
            'located_at' => now()->subMinutes(30),
        ]);

        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/dashboard/overview', []);

        $response->assertOk()
            ->assertJsonStructure([
                'metrics' => [
                    'pending_pre_plan_orders',
                    'assigned_tasks',
                    'in_progress_tasks',
                    'online_drivers',
                    'exception_alerts',
                ],
                'today' => [
                    'created_tasks',
                    'completed_tasks',
                    'task_completion_rate',
                ],
                'generated_at',
            ])
            ->assertJsonPath('metrics.pending_pre_plan_orders', 2)
            ->assertJsonPath('metrics.assigned_tasks', 1)
            ->assertJsonPath('metrics.in_progress_tasks', 1)
            ->assertJsonPath('metrics.online_drivers', 1)
            ->assertJsonPath('metrics.exception_alerts', 1)
            ->assertJsonPath('today.created_tasks', 5)
            ->assertJsonPath('today.completed_tasks', 2)
            ->assertJsonPath('today.task_completion_rate', 40);
    }
}
