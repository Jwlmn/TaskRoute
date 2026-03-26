<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('role', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/users', [
            'name' => '测试调度员',
            'email' => 'ops-new@taskroute.local',
            'phone' => '13810000000',
            'role' => 'dispatcher',
            'status' => 'active',
            'password' => 'TaskRoute@123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'ops-new@taskroute.local');
    }

    public function test_dispatcher_cannot_create_user_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/users', [
            'name' => '非法创建',
            'email' => 'blocked@taskroute.local',
            'role' => 'driver',
            'password' => 'TaskRoute@123',
        ]);

        $response->assertForbidden();
    }
}

