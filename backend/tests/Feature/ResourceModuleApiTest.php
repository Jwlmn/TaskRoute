<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResourceModuleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_can_list_vehicle_resources(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('account', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/resource/vehicle/list', []);
        $response->assertOk()->assertJsonStructure(['data']);
    }

    public function test_admin_can_create_site_resource(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('account', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/resource/site/create', [
            'name' => '测试提货点',
            'site_type' => 'pickup',
            'address' => '上海市浦东新区测试路1号',
            'contact_person' => '测试联系人',
            'contact_phone' => '13988888888',
        ]);

        $response->assertCreated()->assertJsonPath('name', '测试提货点');
    }

    public function test_dispatcher_cannot_create_personnel_resource(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('account', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/resource/personnel/create', [
            'account' => 'driver_new',
            'name' => '新司机',
            'role' => 'driver',
            'password' => '123456',
        ]);

        $response->assertForbidden();
    }
}

