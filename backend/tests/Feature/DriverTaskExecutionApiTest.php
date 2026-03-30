<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\DispatchTask;
use App\Models\PrePlanOrder;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverTaskExecutionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_execute_task_nodes_and_upload_document(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $order = PrePlanOrder::query()
            ->whereIn('status', ['pending', 'scheduled'])
            ->where('cargo_category_id', $gasoline->id)
            ->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                    'estimated_distance_km' => 10.5,
                    'estimated_fuel_l' => 9.2,
                    'estimated_duration_min' => 20,
                ],
            ],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId]);
        $detailResponse->assertOk();
        $this->assertCount(1, $detailResponse->json('waypoints'));

        $startResponse = $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId]);
        $startResponse->assertOk()->assertJsonPath('status', 'accepted');

        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/waypoint-arrive', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
            'lng' => 121.47,
            'lat' => 31.23,
        ])->assertOk()->assertJsonPath('status', 'arrived');

        Storage::fake('public');
        $uploadResponse = $this->post('/api/v1/driver-task/upload-document', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
            'document_type' => 'photo',
            'remark' => '装货完成留存',
            'document_file' => UploadedFile::fake()->image('proof.jpg'),
        ]);
        $uploadResponse->assertCreated()->assertJsonPath('document_type', 'photo');

        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('electronic_documents', [
            'dispatch_task_id' => $taskId,
            'task_waypoint_id' => $waypointId,
            'uploaded_by' => $driver->id,
            'document_type' => 'photo',
        ]);
        $this->assertSame('completed', DispatchTask::query()->findOrFail($taskId)->status);
    }

    public function test_driver_can_upload_multiple_documents_for_same_waypoint(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->whereIn('status', ['pending', 'scheduled'])->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                ],
            ],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId]);
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();

        Storage::fake('public');
        $response = $this->post('/api/v1/driver-task/upload-document', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
            'document_type' => 'photo',
            'document_files' => [
                UploadedFile::fake()->image('proof-1.jpg'),
                UploadedFile::fake()->image('proof-2.jpg'),
            ],
        ]);

        $response->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseCount('electronic_documents', 2);
        $this->assertDatabaseHas('electronic_documents', [
            'dispatch_task_id' => $taskId,
            'task_waypoint_id' => $waypointId,
            'document_type' => 'photo',
        ]);
    }

    public function test_driver_complete_current_trip_will_auto_dispatch_next_trip_for_same_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();
        $orders = PrePlanOrder::query()
            ->where('status', 'pending')
            ->where('cargo_category_id', $gasoline->id)
            ->orderBy('expected_pickup_at')
            ->limit(2)
            ->get();
        $this->assertCount(2, $orders);

        PrePlanOrder::query()
            ->where('status', 'pending')
            ->whereNotIn('id', [$orders[0]->id, $orders[1]->id])
            ->update(['status' => 'completed']);

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$orders[0]->id],
                ],
            ],
        ]);
        $createResponse->assertCreated();
        $firstTaskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $firstTaskId]);
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $firstTaskId])->assertOk();

        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $firstTaskId,
            'waypoint_id' => $waypointId,
        ])->assertOk()->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('dispatch_tasks', [
            'id' => $firstTaskId,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $orders[0]->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $orders[1]->id,
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'status' => 'busy',
        ]);
        $this->assertDatabaseHas('dispatch_task_orders', [
            'pre_plan_order_id' => $orders[1]->id,
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('dispatch_tasks', [
            'vehicle_id' => $vehicle->id,
            'status' => 'assigned',
        ]);
    }

    public function test_driver_cannot_operate_waypoint_or_upload_before_accepting_task(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->whereIn('status', ['pending', 'scheduled'])->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                ],
            ],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId]);
        $waypointId = (int) $detailResponse->json('waypoints.0.id');

        $this->postJson('/api/v1/driver-task/waypoint-arrive', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertStatus(422)->assertJsonPath('message', '请先接单后再执行节点操作');

        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertStatus(422)->assertJsonPath('message', '请先接单后再执行节点操作');

        Storage::fake('public');
        $this->post('/api/v1/driver-task/upload-document', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
            'document_type' => 'photo',
            'document_file' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertStatus(422)->assertJsonPath('message', '请先接单后再上传单据');
    }

    public function test_driver_cannot_operate_waypoint_or_upload_when_exception_is_pending(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->whereIn('status', ['pending', 'scheduled'])->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                ],
            ],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId]);
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '高架堵车，等待调度处理',
        ])->assertOk();

        $this->postJson('/api/v1/driver-task/waypoint-arrive', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertStatus(422)->assertJsonPath('message', '当前任务存在待处理异常，请等待调度处理后再执行节点');

        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertStatus(422)->assertJsonPath('message', '当前任务存在待处理异常，请等待调度处理后再执行节点');

        Storage::fake('public');
        $this->post('/api/v1/driver-task/upload-document', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
            'document_type' => 'photo',
            'document_file' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertStatus(422)->assertJsonPath('message', '当前任务存在待处理异常，请等待调度处理后再上传单据');
    }

    public function test_driver_start_and_report_exception_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->whereIn('status', ['pending', 'scheduled'])->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                ],
            ],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk()
            ->assertJsonPath('status', 'accepted');
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk()
            ->assertJsonPath('status', 'accepted');

        $firstReport = $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '幂等测试-拥堵',
        ]);
        $firstReport->assertOk()
            ->assertJsonPath('route_meta.exception.status', 'pending')
            ->assertJsonCount(1, 'route_meta.exception.history');

        $secondReport = $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '幂等测试-拥堵',
        ]);
        $secondReport->assertOk()
            ->assertJsonPath('route_meta.exception.status', 'pending')
            ->assertJsonCount(1, 'route_meta.exception.history');

        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'other',
            'description' => '不同异常请求',
        ])->assertStatus(422)->assertJsonPath('message', '当前任务已有待处理异常，请勿重复上报');
    }

    public function test_order_completed_will_auto_calculate_freight_by_weight(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();

        $order = PrePlanOrder::query()->create([
            'order_no' => 'PO-FREIGHT-WEIGHT',
            'cargo_category_id' => $gasoline->id,
            'client_name' => '运费重量方案客户',
            'pickup_address' => '上海装货点A',
            'dropoff_address' => '上海卸货点B',
            'cargo_weight_kg' => 3500,
            'actual_delivered_weight_kg' => 2900,
            'loss_allowance_kg' => 200,
            'loss_deduct_unit_price' => 150,
            'cargo_volume_m3' => 6,
            'status' => 'pending',
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 120,
        ]);

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                ],
            ],
        ]);
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId]);
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertOk();

        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $order->id,
            'status' => 'completed',
            'freight_base_amount' => 420.00,
            'freight_loss_deduct_amount' => 60.00,
            'freight_amount' => 360.00,
        ]);
    }

    public function test_order_completed_will_auto_calculate_freight_by_volume_and_apply_loss_deduction(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();

        $order = PrePlanOrder::query()->create([
            'order_no' => 'PO-FREIGHT-VOLUME-LOSS',
            'cargo_category_id' => $gasoline->id,
            'client_name' => '按体积+亏吨扣减客户',
            'pickup_address' => '上海装货点C',
            'dropoff_address' => '上海卸货点D',
            'cargo_weight_kg' => 3500,
            'actual_delivered_weight_kg' => 3000,
            'loss_allowance_kg' => 200,
            'loss_deduct_unit_price' => 300,
            'cargo_volume_m3' => 6,
            'status' => 'pending',
            'freight_calc_scheme' => 'by_volume',
            'freight_unit_price' => 300,
        ]);

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                ],
            ],
        ]);
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId]);
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertOk();

        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $order->id,
            'status' => 'completed',
            'freight_base_amount' => 1800.00,
            'freight_loss_deduct_amount' => 90.00,
            'freight_amount' => 1710.00,
        ]);
    }

    public function test_weight_scheme_should_not_deduct_when_loss_within_allowance(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->firstOrFail();

        $order = PrePlanOrder::query()->create([
            'order_no' => 'PO-FREIGHT-WEIGHT-NO-DEDUCT',
            'cargo_category_id' => $gasoline->id,
            'client_name' => '亏吨容差客户',
            'pickup_address' => '上海装货点E',
            'dropoff_address' => '上海卸货点F',
            'cargo_weight_kg' => 30000,
            'actual_delivered_weight_kg' => 29900,
            'loss_allowance_kg' => 150,
            'loss_deduct_unit_price' => 200,
            'status' => 'pending',
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 100,
        ]);

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [
                [
                    'vehicle_id' => $vehicle->id,
                    'order_ids' => [$order->id],
                ],
            ],
        ]);
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $detailResponse = $this->postJson('/api/v1/driver-task/detail', ['task_id' => $taskId]);
        $waypointId = (int) $detailResponse->json('waypoints.0.id');
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertOk();

        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $order->id,
            'freight_base_amount' => 3000.00,
            'freight_loss_deduct_amount' => 0.00,
            'freight_amount' => 3000.00,
        ]);
    }

    public function test_driver_cannot_access_other_driver_task_by_payload_interfaces(): void
    {
        $this->seed(DatabaseSeeder::class);

        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $otherDriver = User::query()->where('account', 'driver2')->firstOrFail();

        $task = DispatchTask::query()->create([
            'task_no' => 'DT-OTHER-DRIVER-ONLY',
            'driver_id' => $otherDriver->id,
            'status' => 'assigned',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver-task/detail', [
            'task_id' => $task->id,
        ])->assertNotFound();

        $this->postJson('/api/v1/driver-task/start', [
            'task_id' => $task->id,
        ])->assertNotFound();
    }
}
