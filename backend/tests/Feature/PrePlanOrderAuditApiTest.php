<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrePlanOrderAuditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_submit_order_and_dispatcher_can_approve(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '客户自助下单A',
            'pickup_address' => '上海仓A',
            'dropoff_address' => '上海店B',
            'cargo_weight_kg' => 1200,
            'cargo_volume_m3' => 3.5,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 120,
        ]);
        $submitResponse->assertCreated()
            ->assertJsonPath('audit_status', 'pending_approval')
            ->assertJsonPath('submitter_id', $customer->id);
        $orderId = (int) $submitResponse->json('id');

        $listResponse = $this->postJson('/api/v1/pre-plan-order/customer-list', []);
        $listResponse->assertOk()->assertJsonPath('data.0.id', $orderId);

        Sanctum::actingAs($dispatcher);
        $approveResponse = $this->postJson('/api/v1/pre-plan-order/audit-approve', [
            'id' => $orderId,
            'audit_remark' => '资料完整，审核通过',
        ]);
        $approveResponse->assertOk()
            ->assertJsonPath('audit_status', 'approved')
            ->assertJsonPath('audited_by', $dispatcher->id);
    }

    public function test_unapproved_order_cannot_be_dispatched_until_approved(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $driver = User::query()->where('account', 'driver')->firstOrFail();
        $vehicle = Vehicle::query()->where('driver_id', $driver->id)->where('status', 'idle')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '客户自助下单B',
            'pickup_address' => '上海仓C',
            'dropoff_address' => '上海店D',
            'cargo_weight_kg' => 1000,
            'cargo_volume_m3' => 2,
        ]);
        $orderId = (int) $submitResponse->json('id');

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/dispatch/preview', [
            'order_ids' => [$orderId],
            'vehicle_ids' => [$vehicle->id],
        ])->assertStatus(422)->assertJsonPath('message', '没有可调度的预计划单');

        $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$orderId],
            ]],
        ])->assertStatus(422)->assertJsonPath('message', '存在不可下发的预计划单（状态非待调度/已排程或未审核通过）');

        $this->postJson('/api/v1/pre-plan-order/audit-approve', ['id' => $orderId])->assertOk();

        $createTaskResponse = $this->postJson('/api/v1/dispatch/manual-create-tasks', [
            'assignments' => [[
                'vehicle_id' => $vehicle->id,
                'order_ids' => [$orderId],
            ]],
        ]);
        $createTaskResponse->assertCreated()->assertJsonCount(1, 'created_task_ids');

        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $orderId,
            'audit_status' => 'approved',
            'status' => 'scheduled',
        ]);
    }
}
