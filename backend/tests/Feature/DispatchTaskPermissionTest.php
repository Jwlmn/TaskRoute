<?php

namespace Tests\Feature;

use App\Models\DispatchTask;
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
        $response = $this->getJson('/api/v1/dispatch-tasks');

        $response->assertOk();
        $taskNos = collect($response->json('data'))->pluck('task_no')->all();
        $this->assertContains('DT-TEST-OWN', $taskNos);
        $this->assertNotContains('DT-TEST-OTHER', $taskNos);
    }
}

