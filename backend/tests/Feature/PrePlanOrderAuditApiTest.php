<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\PrePlanOrder;
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
            'pickup_contact_name' => '装货联系人A',
            'pickup_contact_phone' => '13911110001',
            'dropoff_address' => '上海店B',
            'dropoff_contact_name' => '收货联系人A',
            'dropoff_contact_phone' => '13911110002',
            'cargo_weight_kg' => 1200,
            'cargo_volume_m3' => 3.5,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 120,
        ]);
        $submitResponse->assertCreated()
            ->assertJsonPath('audit_status', 'pending_approval')
            ->assertJsonPath('submitter_id', $customer->id)
            ->assertJsonPath('pickup_contact_name', '装货联系人A')
            ->assertJsonPath('dropoff_contact_name', '收货联系人A');
        $orderId = (int) $submitResponse->json('id');

        $listResponse = $this->postJson('/api/v1/pre-plan-order/customer-list', []);
        $listResponse->assertOk();
        $this->assertTrue(
            collect($listResponse->json('data'))->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $orderId),
            '客户订单列表应包含刚提交的计划单'
        );

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

    public function test_customer_can_modify_rejected_order_and_resubmit_with_message_read_flow(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '客户自助下单C',
            'pickup_address' => '上海仓X',
            'dropoff_address' => '上海店Y',
            'cargo_weight_kg' => 2000,
            'cargo_volume_m3' => 4,
        ])->assertCreated();
        $orderId = (int) $submitResponse->json('id');

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/pre-plan-order/audit-reject', [
            'id' => $orderId,
            'audit_remark' => '地址信息不完整，请补充后重提',
        ])->assertOk()->assertJsonPath('audit_status', 'rejected');

        Sanctum::actingAs($customer);
        $messageListResponse = $this->postJson('/api/v1/message/list', ['unread_only' => true]);
        $messageListResponse->assertOk();
        $targetMessage = collect($messageListResponse->json('data'))
            ->first(fn (array $item): bool => (int) data_get($item, 'meta.order_id') === $orderId);
        $this->assertNotNull($targetMessage, '客户未读消息中应包含当前驳回订单的审核消息');
        $this->assertSame('rejected', data_get($targetMessage, 'meta.audit_status'));
        $messageId = (int) data_get($targetMessage, 'id');

        $this->postJson('/api/v1/message/read', ['id' => $messageId])
            ->assertOk();

        $this->postJson('/api/v1/pre-plan-order/customer-update', [
            'id' => $orderId,
            'pickup_address' => '上海仓X（已补充月台信息）',
        ])->assertOk()->assertJsonPath('pickup_address', '上海仓X（已补充月台信息）');

        $this->postJson('/api/v1/pre-plan-order/customer-resubmit', [
            'id' => $orderId,
        ])->assertOk()->assertJsonPath('audit_status', 'pending_approval');
    }

    public function test_dispatcher_can_batch_create_pre_plan_orders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/pre-plan-order/batch-create', [
            'orders' => [
                [
                    'cargo_category_id' => $cargo->id,
                    'client_name' => '批量客户A',
                    'pickup_address' => '批量装货地A',
                    'dropoff_address' => '批量卸货地A',
                ],
                [
                    'cargo_category_id' => $cargo->id,
                    'client_name' => '批量客户B',
                    'pickup_address' => '批量装货地B',
                    'dropoff_address' => '批量卸货地B',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('data.0.audit_status', 'approved');
    }

    public function test_dispatcher_can_lock_unlock_and_void_pre_plan_order(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/pre-plan-order/lock', ['id' => $order->id])
            ->assertOk()
            ->assertJsonPath('is_locked', true);

        $this->postJson('/api/v1/pre-plan-order/unlock', ['id' => $order->id])
            ->assertOk()
            ->assertJsonPath('is_locked', false);

        $this->postJson('/api/v1/pre-plan-order/void', [
            'id' => $order->id,
            'void_remark' => '客户取消，本单作废',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('void_remark', '客户取消，本单作废');
    }
}
