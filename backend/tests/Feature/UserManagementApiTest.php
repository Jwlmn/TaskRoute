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

        $response = $this->postJson('/api/v1/user/create', [
            'account' => 'ops_new',
            'name' => '测试调度员',
            'phone' => '13810000000',
            'role' => 'dispatcher',
            'status' => 'active',
            'password' => 'TaskRoute@123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('account', 'ops_new');
    }

    public function test_dispatcher_cannot_create_user_account(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/user/create', [
            'account' => 'blocked_user',
            'name' => '非法创建',
            'role' => 'driver',
            'password' => 'TaskRoute@123',
        ]);

        $response->assertForbidden();
    }
}
