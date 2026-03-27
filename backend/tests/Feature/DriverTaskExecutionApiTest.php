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
}
