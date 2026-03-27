<?php

namespace Tests\Feature;

use App\Models\PrePlanOrder;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DispatchExceptionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_report_exception_and_dispatcher_can_list_pending_exception_tasks(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'traffic_jam',
            'description' => '高架拥堵，预计延后30分钟',
        ])->assertOk()
            ->assertJsonPath('route_meta.exception.status', 'pending')
            ->assertJsonPath('route_meta.exception.type', 'traffic_jam');

        Sanctum::actingAs($dispatcher);
        $listResponse = $this->postJson('/api/v1/dispatch-task/exception-list', [
            'status' => 'pending',
        ]);
        $listResponse->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $taskId)
            ->assertJsonPath('data.0.route_meta.exception.description', '高架拥堵，预计延后30分钟');
    }

    public function test_dispatcher_can_handle_exception_by_reassign_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $targetVehicle = Vehicle::query()
            ->where('id', '!=', $vehicle->id)
            ->where('status', 'idle')
            ->whereNotNull('driver_id')
            ->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();

        Sanctum::actingAs($dispatcher);
        $createResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$order->id],
            ]],
        ]);
        $createResponse->assertCreated();
        $taskId = (int) $createResponse->json('created_task_ids.0');

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-task/start', ['task_id' => $taskId])->assertOk();
        $this->postJson('/api/v1/driver-task/report-exception', [
            'task_id' => $taskId,
            'exception_type' => 'vehicle_breakdown',
            'description' => '车辆故障，无法继续执行',
        ])->assertOk();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch-task/exception-handle', [
            'task_id' => $taskId,
            'action' => 'reassign',
            'reassign_vehicle_id' => $targetVehicle->id,
            'handle_note' => '改派备用车辆继续执行',
        ])->assertOk()
            ->assertJsonPath('status', 'assigned')
            ->assertJsonPath('vehicle_id', $targetVehicle->id)
            ->assertJsonPath('driver_id', $targetVehicle->driver_id)
            ->assertJsonPath('route_meta.exception.status', 'handled')
            ->assertJsonPath('route_meta.exception.handle_action', 'reassign');

        $this->assertDatabaseHas('vehicles', [
            'id' => $vehicle->id,
            'status' => 'idle',
        ]);
        $this->assertDatabaseHas('vehicles', [
            'id' => $targetVehicle->id,
            'status' => 'busy',
        ]);
    }
}
