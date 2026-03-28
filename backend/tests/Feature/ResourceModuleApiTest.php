<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
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

    public function test_admin_can_create_customer_personnel_resource(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('account', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/resource/personnel/create', [
            'account' => 'customer_new',
            'name' => '客户新账号',
            'role' => 'customer',
            'status' => 'active',
            'password' => '123456',
        ]);

        $response->assertCreated()
            ->assertJsonPath('account', 'customer_new')
            ->assertJsonPath('role', 'customer');
    }

    public function test_cannot_bind_one_driver_to_multiple_vehicles_on_create(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('account', 'admin')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/resource/vehicle/create', [
            'plate_number' => '沪C66666',
            'name' => '新增测试车',
            'vehicle_type' => 'truck',
            'driver_id' => $driver->id,
            'max_weight_kg' => 10000,
            'max_volume_m3' => 20,
            'status' => 'idle',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', '该司机已绑定其他车辆，请先解绑后再分配');
    }

    public function test_cannot_bind_one_driver_to_multiple_vehicles_on_update(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('account', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $vehicleA = Vehicle::query()->where('plate_number', '沪A12345')->firstOrFail();
        $vehicleB = Vehicle::query()->where('plate_number', '沪B88990')->firstOrFail();

        $response = $this->postJson('/api/v1/resource/vehicle/update', [
            'id' => $vehicleB->id,
            'driver_id' => $vehicleA->driver_id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', '该司机已绑定其他车辆，请先解绑后再分配');
    }
}
