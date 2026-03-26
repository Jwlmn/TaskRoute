<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrePlanOrderPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_cannot_list_pre_plan_orders(): void
    {
        $this->seed();
        $driver = User::query()->where('role', 'driver')->firstOrFail();
        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/v1/pre-plan-order/list', []);

        $response->assertForbidden();
    }

    public function test_driver_cannot_create_pre_plan_orders(): void
    {
        $this->seed();
        $driver = User::query()->where('role', 'driver')->firstOrFail();
        Sanctum::actingAs($driver);

        $response = $this->postJson('/api/v1/pre-plan-order/create', [
            'cargo_category_id' => 1,
            'client_name' => '测试客户',
            'pickup_address' => '测试装货地',
            'dropoff_address' => '测试卸货地',
        ]);

        $response->assertForbidden();
    }
}
