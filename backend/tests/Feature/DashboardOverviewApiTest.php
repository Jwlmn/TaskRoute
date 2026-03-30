<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\DispatchTask;
use App\Models\DriverLocation;
use App\Models\ElectronicDocument;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\TaskWaypoint;
use App\Models\User;
use App\Models\Vehicle;
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
        $driverC = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);
        $siteA = LogisticsSite::factory()->create([
            'name' => '看板站点A',
            'region_code' => 'SH-PD',
            'site_type' => 'both',
            'status' => 'active',
        ]);
        $siteB = LogisticsSite::factory()->create([
            'name' => '看板站点B',
            'region_code' => 'SH-JD',
            'site_type' => 'both',
            'status' => 'active',
        ]);
        $siteC = LogisticsSite::factory()->create([
            'name' => '看板站点C',
            'region_code' => 'SH-PT',
            'site_type' => 'both',
            'status' => 'active',
        ]);

        $vehicleA = Vehicle::query()->create([
            'plate_number' => '沪DASH001',
            'name' => '看板测试车A',
            'vehicle_type' => 'truck',
            'site_id' => $siteA->id,
            'driver_id' => $driverA->id,
            'max_weight_kg' => 8000,
            'max_volume_m3' => 20,
            'status' => 'busy',
        ]);
        $vehicleB = Vehicle::query()->create([
            'plate_number' => '沪DASH002',
            'name' => '看板测试车B',
            'vehicle_type' => 'truck',
            'site_id' => $siteB->id,
            'driver_id' => $driverB->id,
            'max_weight_kg' => 8000,
            'max_volume_m3' => 20,
            'status' => 'idle',
        ]);
        $vehicleC = Vehicle::query()->create([
            'plate_number' => '沪DASH003',
            'name' => '看板测试车C',
            'vehicle_type' => 'truck',
            'site_id' => $siteC->id,
            'driver_id' => $driverC->id,
            'max_weight_kg' => 8000,
            'max_volume_m3' => 20,
            'status' => 'idle',
        ]);

        $cargo = CargoCategory::query()->create([
            'name' => '汽油',
            'code' => 'gasoline-test',
        ]);

        PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-001',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户A',
            'pickup_site_id' => $siteA->id,
            'pickup_address' => '上海A',
            'dropoff_site_id' => $siteB->id,
            'dropoff_address' => '上海B',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);
        PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-002',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户B',
            'pickup_site_id' => $siteA->id,
            'pickup_address' => '上海C',
            'dropoff_site_id' => $siteB->id,
            'dropoff_address' => '上海D',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);
        PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-003',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户C',
            'pickup_site_id' => $siteA->id,
            'pickup_address' => '上海E',
            'dropoff_site_id' => $siteC->id,
            'dropoff_address' => '上海F',
            'status' => 'pending',
            'audit_status' => 'pending_approval',
        ]);
        PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-004',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户D',
            'pickup_site_id' => $siteB->id,
            'pickup_address' => '上海G',
            'dropoff_site_id' => $siteC->id,
            'dropoff_address' => '上海H',
            'status' => 'completed',
            'expected_delivery_at' => now()->addHour(),
            'freight_amount' => 600,
            'created_at' => now()->subHours(2),
            'updated_at' => now(),
        ]);
        PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-005',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户E',
            'pickup_site_id' => $siteB->id,
            'pickup_address' => '上海I',
            'dropoff_site_id' => $siteC->id,
            'dropoff_address' => '上海J',
            'status' => 'completed',
            'expected_delivery_at' => now()->subHour(),
            'freight_amount' => 450,
            'created_at' => now()->subHours(3),
            'updated_at' => now(),
        ]);
        $yesterdayCompletedOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-DASH-006',
            'cargo_category_id' => $cargo->id,
            'client_name' => '测试客户F',
            'pickup_site_id' => $siteB->id,
            'pickup_address' => '上海K',
            'dropoff_site_id' => $siteC->id,
            'dropoff_address' => '上海L',
            'status' => 'completed',
            'expected_delivery_at' => now()->subDay(),
            'freight_amount' => 300,
        ]);
        $yesterdayCompletedOrder->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();

        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-001',
            'vehicle_id' => $vehicleA->id,
            'driver_id' => $driverA->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-002',
            'vehicle_id' => $vehicleA->id,
            'driver_id' => $driverA->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'in_progress',
            'route_meta' => [
                'exception' => [
                    'status' => 'pending',
                    'type' => 'traffic_jam',
                    'description' => '测试看板待处理异常',
                ],
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $completedTaskWithReceipt = DispatchTask::query()->create([
            'task_no' => 'DT-DASH-003',
            'vehicle_id' => $vehicleB->id,
            'driver_id' => $driverB->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DispatchTask::query()->create([
            'task_no' => 'DT-DASH-004',
            'vehicle_id' => $vehicleC->id,
            'driver_id' => $driverC->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'cancelled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $yesterdayCompletedTask = DispatchTask::query()->create([
            'task_no' => 'DT-DASH-005',
            'vehicle_id' => $vehicleA->id,
            'driver_id' => $driverA->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'completed',
        ]);
        $yesterdayCompletedTask->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();
        $completedTaskWithoutReceipt = DispatchTask::query()->create([
            'task_no' => 'DT-DASH-006',
            'vehicle_id' => $vehicleC->id,
            'driver_id' => $driverC->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
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

        $waypoint = TaskWaypoint::query()->create([
            'dispatch_task_id' => $completedTaskWithReceipt->id,
            'node_type' => 'pickup',
            'sequence' => 1,
            'address' => '上海看板测试站点',
            'status' => 'completed',
            'arrived_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(10),
        ]);

        ElectronicDocument::query()->create([
            'dispatch_task_id' => $completedTaskWithReceipt->id,
            'task_waypoint_id' => $waypoint->id,
            'uploaded_by' => $driverB->id,
            'document_type' => 'receipt',
            'file_path' => 'electronic-documents/dash-receipt.jpg',
            'uploaded_at' => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/dashboard/overview', []);

        $response->assertOk()
            ->assertJsonStructure([
                'metrics' => [
                    'pending_pre_plan_orders',
                    'pending_approval_orders',
                    'assigned_tasks',
                    'in_progress_tasks',
                    'online_drivers',
                    'exception_alerts',
                    'busy_vehicles',
                    'total_vehicles',
                ],
                'today' => [
                    'created_tasks',
                    'completed_tasks',
                    'completed_orders',
                    'receipt_uploaded_tasks',
                    'total_freight_amount',
                ],
                'rates' => [
                    'task_completion_rate',
                    'vehicle_utilization_rate',
                    'on_time_order_rate',
                    'receipt_upload_rate',
                    'driver_fulfillment_rate',
                ],
                'site_stats',
                'pending_exceptions',
                'generated_at',
            ])
            ->assertJsonPath('metrics.pending_pre_plan_orders', 2)
            ->assertJsonPath('metrics.pending_approval_orders', 1)
            ->assertJsonPath('metrics.assigned_tasks', 1)
            ->assertJsonPath('metrics.in_progress_tasks', 1)
            ->assertJsonPath('metrics.online_drivers', 1)
            ->assertJsonPath('metrics.exception_alerts', 1)
            ->assertJsonPath('metrics.busy_vehicles', 1)
            ->assertJsonPath('metrics.total_vehicles', 3)
            ->assertJsonPath('today.created_tasks', 5)
            ->assertJsonPath('today.completed_tasks', 2)
            ->assertJsonPath('today.completed_orders', 2)
            ->assertJsonPath('today.receipt_uploaded_tasks', 1)
            ->assertJsonPath('today.total_freight_amount', 1050)
            ->assertJsonPath('rates.task_completion_rate', 40)
            ->assertJsonPath('rates.vehicle_utilization_rate', 33.33)
            ->assertJsonPath('rates.on_time_order_rate', 50)
            ->assertJsonPath('rates.receipt_upload_rate', 50)
            ->assertJsonPath('rates.driver_fulfillment_rate', 50)
            ->assertJsonPath('site_stats.0.site_name', '看板站点A')
            ->assertJsonPath('site_stats.0.pending_pre_plan_orders', 2)
            ->assertJsonPath('site_stats.0.assigned_tasks', 1)
            ->assertJsonPath('site_stats.0.in_progress_tasks', 1)
            ->assertJsonPath('site_stats.0.busy_vehicles', 1)
            ->assertJsonPath('pending_exceptions.0.task_no', 'DT-DASH-002')
            ->assertJsonPath('pending_exceptions.0.exception_type', 'traffic_jam')
            ->assertJsonPath('pending_exceptions.0.driver_name', $driverA->name)
            ->assertJsonPath('pending_exceptions.0.vehicle_plate_number', $vehicleA->plate_number);
    }

    public function test_region_scoped_dispatcher_only_sees_scoped_dashboard_metrics(): void
    {
        $siteInScope = LogisticsSite::factory()->create([
            'region_code' => 'SH-PD',
            'site_type' => 'both',
            'status' => 'active',
        ]);
        $siteOutScope = LogisticsSite::factory()->create([
            'region_code' => 'SH-JD',
            'site_type' => 'both',
            'status' => 'active',
        ]);
        $dispatcher = User::factory()->create([
            'role' => 'dispatcher',
            'status' => 'active',
            'data_scope_type' => 'region',
            'data_scope' => ['region_codes' => ['SH-PD']],
        ]);
        $driverA = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $driverB = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $vehicleInScope = Vehicle::query()->create([
            'plate_number' => '沪SCOPE001',
            'name' => '范围内车辆',
            'vehicle_type' => 'truck',
            'site_id' => $siteInScope->id,
            'driver_id' => $driverA->id,
            'max_weight_kg' => 8000,
            'max_volume_m3' => 20,
            'status' => 'busy',
        ]);
        Vehicle::query()->create([
            'plate_number' => '沪SCOPE002',
            'name' => '范围外车辆',
            'vehicle_type' => 'truck',
            'site_id' => $siteOutScope->id,
            'driver_id' => $driverB->id,
            'max_weight_kg' => 8000,
            'max_volume_m3' => 20,
            'status' => 'idle',
        ]);

        $cargo = CargoCategory::query()->create([
            'name' => '范围测试货品',
            'code' => 'scope-goods',
        ]);

        PrePlanOrder::query()->create([
            'order_no' => 'PO-SCOPE-001',
            'cargo_category_id' => $cargo->id,
            'client_name' => '范围内客户',
            'pickup_site_id' => $siteInScope->id,
            'pickup_address' => '范围内提货地',
            'dropoff_site_id' => $siteInScope->id,
            'dropoff_address' => '范围内卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);
        PrePlanOrder::query()->create([
            'order_no' => 'PO-SCOPE-002',
            'cargo_category_id' => $cargo->id,
            'client_name' => '范围外客户',
            'pickup_site_id' => $siteOutScope->id,
            'pickup_address' => '范围外提货地',
            'dropoff_site_id' => $siteOutScope->id,
            'dropoff_address' => '范围外卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

        DispatchTask::query()->create([
            'task_no' => 'DT-SCOPE-001',
            'vehicle_id' => $vehicleInScope->id,
            'driver_id' => $driverA->id,
            'dispatcher_id' => $dispatcher->id,
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DriverLocation::query()->create([
            'driver_id' => $driverA->id,
            'dispatch_task_id' => null,
            'lng' => 121.4737010,
            'lat' => 31.2304160,
            'located_at' => now()->subMinutes(3),
        ]);
        DriverLocation::query()->create([
            'driver_id' => $driverB->id,
            'dispatch_task_id' => null,
            'lng' => 121.4737010,
            'lat' => 31.2304160,
            'located_at' => now()->subMinutes(3),
        ]);

        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/dashboard/overview', []);

        $response->assertOk()
            ->assertJsonPath('metrics.pending_pre_plan_orders', 1)
            ->assertJsonPath('metrics.assigned_tasks', 1)
            ->assertJsonPath('metrics.online_drivers', 1)
            ->assertJsonPath('metrics.busy_vehicles', 1)
            ->assertJsonPath('metrics.total_vehicles', 1);
    }
}
