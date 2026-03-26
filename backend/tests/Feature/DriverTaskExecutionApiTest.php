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

        $this->postJson('/api/v1/driver-task/waypoint-complete', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
        ])->assertOk()->assertJsonPath('status', 'completed');

        Storage::fake('public');
        $uploadResponse = $this->post('/api/v1/driver-task/upload-document', [
            'task_id' => $taskId,
            'waypoint_id' => $waypointId,
            'document_type' => 'photo',
            'remark' => '装货完成留存',
            'document_file' => UploadedFile::fake()->image('proof.jpg'),
        ]);
        $uploadResponse->assertCreated()->assertJsonPath('document_type', 'photo');

        $this->assertDatabaseHas('electronic_documents', [
            'dispatch_task_id' => $taskId,
            'task_waypoint_id' => $waypointId,
            'uploaded_by' => $driver->id,
            'document_type' => 'photo',
        ]);
        $this->assertSame('completed', DispatchTask::query()->findOrFail($taskId)->status);
    }
}
