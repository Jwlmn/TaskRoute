<?php

namespace Tests\Feature;

use App\Models\DispatchTask;
use App\Models\DriverLocation;
use App\Models\LogisticsSite;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverLocationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_can_report_location_and_dispatcher_can_view_latest_and_trajectory(): void
    {
        $this->seed(DatabaseSeeder::class);

        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->firstOrFail();
        $task = DispatchTask::query()->create([
            'task_no' => 'DT-LOC-TEST-1',
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'dispatcher_id' => $dispatcher->id,
            'dispatch_mode' => 'single_vehicle_single_order',
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($driver);
        $this->postJson('/api/v1/driver-location/report', [
            'dispatch_task_id' => $task->id,
            'lng' => 121.470001,
            'lat' => 31.230002,
            'speed_kmh' => 35.5,
            'located_at' => now()->subMinutes(1)->toDateTimeString(),
        ])->assertCreated();

        $this->postJson('/api/v1/driver-location/report', [
            'dispatch_task_id' => $task->id,
            'lng' => 121.471111,
            'lat' => 31.231111,
            'speed_kmh' => 38,
            'located_at' => now()->toDateTimeString(),
        ])->assertCreated();

        Sanctum::actingAs($dispatcher);
        $latestResponse = $this->postJson('/api/v1/driver-location/latest', []);
        $latestResponse->assertOk();
        $this->assertSame($driver->id, (int) $latestResponse->json('0.driver_id'));

        $trajectoryResponse = $this->postJson('/api/v1/driver-location/trajectory', [
            'driver_id' => $driver->id,
            'dispatch_task_id' => $task->id,
            'limit' => 50,
        ]);
        $trajectoryResponse->assertOk();
        $this->assertCount(2, $trajectoryResponse->json());
        $this->assertSame(121.470001, (float) $trajectoryResponse->json('0.lng'));
        $this->assertSame(121.471111, (float) $trajectoryResponse->json('1.lng'));
    }

    public function test_driver_cannot_report_other_driver_task_location(): void
    {
        $this->seed(DatabaseSeeder::class);
        $driverA = User::query()->where('account', 'driver')->firstOrFail();
        $driverB = User::query()->where('account', 'driver2')->firstOrFail();
        $vehicleB = Vehicle::query()->where('driver_id', $driverB->id)->firstOrFail();
        $taskOfDriverB = DispatchTask::query()->create([
            'task_no' => 'DT-LOC-TEST-2',
            'driver_id' => $driverB->id,
            'vehicle_id' => $vehicleB->id,
            'dispatch_mode' => 'single_vehicle_single_order',
            'status' => 'in_progress',
        ]);

        Sanctum::actingAs($driverA);
        $response = $this->postJson('/api/v1/driver-location/report', [
            'dispatch_task_id' => $taskOfDriverB->id,
            'lng' => 121.47,
            'lat' => 31.23,
        ]);

        $response->assertForbidden();
        $this->assertSame(0, DriverLocation::query()->count());
    }

    public function test_dispatcher_location_queries_respect_site_scope(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driverInScope = User::query()->where('account', 'driver')->firstOrFail();
        $driverOutScope = User::query()->where('account', 'driver2')->firstOrFail();
        $siteInScope = LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteOutScope = LogisticsSite::query()
            ->where('id', '!=', $siteInScope->id)
            ->orderBy('id')
            ->firstOrFail();

        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteInScope->id]],
        ])->save();

        $vehicleInScope = Vehicle::query()->where('driver_id', $driverInScope->id)->firstOrFail();
        $vehicleInScope->forceFill(['site_id' => $siteInScope->id])->save();

        $vehicleOutScope = Vehicle::query()->where('driver_id', $driverOutScope->id)->firstOrFail();
        $vehicleOutScope->forceFill(['site_id' => $siteOutScope->id])->save();

        $taskInScope = DispatchTask::query()->create([
            'task_no' => 'DT-LOC-SCOPE-IN',
            'driver_id' => $driverInScope->id,
            'vehicle_id' => $vehicleInScope->id,
            'dispatcher_id' => $dispatcher->id,
            'dispatch_mode' => 'single_vehicle_single_order',
            'status' => 'in_progress',
        ]);
        $taskOutScope = DispatchTask::query()->create([
            'task_no' => 'DT-LOC-SCOPE-OUT',
            'driver_id' => $driverOutScope->id,
            'vehicle_id' => $vehicleOutScope->id,
            'dispatcher_id' => $dispatcher->id,
            'dispatch_mode' => 'single_vehicle_single_order',
            'status' => 'in_progress',
        ]);

        DriverLocation::query()->create([
            'driver_id' => $driverInScope->id,
            'dispatch_task_id' => $taskInScope->id,
            'lng' => 121.470001,
            'lat' => 31.230002,
            'located_at' => now()->subMinute(),
        ]);
        DriverLocation::query()->create([
            'driver_id' => $driverOutScope->id,
            'dispatch_task_id' => $taskOutScope->id,
            'lng' => 121.580001,
            'lat' => 31.330002,
            'located_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($dispatcher);

        $latestResponse = $this->postJson('/api/v1/driver-location/latest', [])->assertOk();
        $latestDriverIds = collect($latestResponse->json())->pluck('driver_id')->values()->all();
        $this->assertSame([$driverInScope->id], $latestDriverIds);

        $trajectoryResponse = $this->postJson('/api/v1/driver-location/trajectory', [
            'driver_id' => $driverOutScope->id,
            'dispatch_task_id' => $taskOutScope->id,
            'limit' => 50,
        ])->assertOk();
        $this->assertCount(0, $trajectoryResponse->json());
    }
}
