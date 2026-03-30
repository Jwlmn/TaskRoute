<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\FreightRateTemplate;
use App\Models\PrePlanOrder;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\SystemMessage;
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

    public function test_customer_update_refreshes_matched_freight_template_meta(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        $templateA = FreightRateTemplate::query()->create([
            'name' => '客户改址模板A',
            'client_name' => '客户模板切换',
            'cargo_category_id' => $cargo->id,
            'pickup_address' => '上海仓A',
            'dropoff_address' => '上海店A',
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 80,
            'priority' => 200,
            'is_active' => true,
        ]);
        $templateB = FreightRateTemplate::query()->create([
            'name' => '客户改址模板B',
            'client_name' => '客户模板切换',
            'cargo_category_id' => $cargo->id,
            'pickup_address' => '上海仓B',
            'dropoff_address' => '上海店B',
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 88,
            'priority' => 200,
            'is_active' => true,
        ]);

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '客户模板切换',
            'pickup_address' => '上海仓A',
            'dropoff_address' => '上海店A',
        ])->assertCreated()
            ->assertJsonPath('meta.freight_template_id', $templateA->id);
        $orderId = (int) $submitResponse->json('id');

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/pre-plan-order/audit-reject', [
            'id' => $orderId,
            'audit_remark' => '请修正地址后重新提交',
        ])->assertOk();

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/pre-plan-order/customer-update', [
            'id' => $orderId,
            'pickup_address' => '上海仓B',
            'dropoff_address' => '上海店B',
        ])->assertOk()
            ->assertJsonPath('meta.freight_template_id', $templateB->id)
            ->assertJsonPath('meta.freight_template_name', $templateB->name);
    }

    public function test_customer_update_clears_stale_freight_template_meta_when_no_match_exists(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        $template = FreightRateTemplate::query()->create([
            'name' => '客户清空模板',
            'client_name' => '客户模板清空',
            'cargo_category_id' => $cargo->id,
            'pickup_address' => '上海仓C',
            'dropoff_address' => '上海店C',
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 66,
            'priority' => 200,
            'is_active' => true,
        ]);

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '客户模板清空',
            'pickup_address' => '上海仓C',
            'dropoff_address' => '上海店C',
        ])->assertCreated()
            ->assertJsonPath('meta.freight_template_id', $template->id);
        $orderId = (int) $submitResponse->json('id');

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/pre-plan-order/audit-reject', [
            'id' => $orderId,
            'audit_remark' => '地址需要重新确认',
        ])->assertOk();

        Sanctum::actingAs($customer);
        $response = $this->postJson('/api/v1/pre-plan-order/customer-update', [
            'id' => $orderId,
            'pickup_address' => '未命中仓库',
            'dropoff_address' => '未命中门店',
        ])->assertOk();

        $this->assertNull(data_get($response->json(), 'meta.freight_template_id'));
        $this->assertNull(data_get($response->json(), 'meta.freight_template_name'));
    }

    public function test_customer_can_view_own_order_detail_with_audit_and_history(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '客户详情查看',
            'pickup_address' => '详情装货地',
            'dropoff_address' => '详情卸货地',
        ])->assertCreated();
        $orderId = (int) $submitResponse->json('id');

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/pre-plan-order/audit-reject', [
            'id' => $orderId,
            'audit_remark' => '请补充装货说明',
        ])->assertOk();

        Sanctum::actingAs($customer);
        $detailResponse = $this->postJson('/api/v1/pre-plan-order/customer-detail', [
            'id' => $orderId,
        ])->assertOk();

        $detailResponse
            ->assertJsonPath('id', $orderId)
            ->assertJsonPath('audit_status', 'rejected')
            ->assertJsonPath('audit_remark', '请补充装货说明')
            ->assertJsonPath('auditor.id', $dispatcher->id)
            ->assertJsonPath('auditor.name', $dispatcher->name);

        $history = collect($detailResponse->json('meta.history'));
        $this->assertTrue($history->contains(fn (array $item): bool => ($item['action'] ?? null) === 'customer_submit'));
        $this->assertTrue($history->contains(fn (array $item): bool => ($item['action'] ?? null) === 'dispatcher_audit_reject'));
    }

    public function test_customer_cannot_view_other_customer_order_detail_or_revision_compare(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $otherCustomer = User::factory()->create([
            'account' => 'customer-other',
            'name' => '其他客户',
            'role' => 'customer',
            'status' => 'active',
            'password' => bcrypt('password'),
        ]);
        $otherCustomer->syncRoleAndPermissions();

        $cargo = CargoCategory::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '隔离详情客户',
            'pickup_address' => '隔离装货地',
            'dropoff_address' => '隔离卸货地',
        ])->assertCreated();
        $orderId = (int) $submitResponse->json('id');

        Sanctum::actingAs($otherCustomer);
        $this->postJson('/api/v1/pre-plan-order/customer-detail', [
            'id' => $orderId,
        ])->assertNotFound();

        $this->postJson('/api/v1/pre-plan-order/revision-compare', [
            'id' => $orderId,
        ])->assertNotFound();
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

    public function test_dispatcher_can_filter_pre_plan_orders_by_keyword_and_lock_status(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $order = PrePlanOrder::query()
            ->where('status', 'pending')
            ->where('client_name', 'like', '%商超%')
            ->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/pre-plan-order/lock', ['id' => $order->id])->assertOk();

        $response = $this->postJson('/api/v1/pre-plan-order/list', [
            'keyword' => '商超',
            'is_locked' => true,
        ]);

        $response->assertOk();
        $this->assertTrue(
            collect($response->json('data'))->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === (int) $order->id),
            '筛选结果应包含已锁定且关键词匹配的计划单'
        );
    }

    public function test_dispatcher_can_split_pending_pre_plan_order(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $weight = (float) $order->cargo_weight_kg;
        $volume = (float) $order->cargo_volume_m3;
        $weightA = round($weight / 2, 2);
        $weightB = round($weight - $weightA, 2);
        $volumeA = round($volume / 2, 2);
        $volumeB = round($volume - $volumeA, 2);

        $response = $this->postJson('/api/v1/pre-plan-order/split', [
            'id' => $order->id,
            'parts' => [
                ['cargo_weight_kg' => $weightA, 'cargo_volume_m3' => $volumeA],
                ['cargo_weight_kg' => $weightB, 'cargo_volume_m3' => $volumeB],
            ],
        ]);

        $response->assertCreated()->assertJsonCount(2, 'created');
        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'void_remark' => '拆单后原单自动作废',
        ]);
    }

    public function test_dispatcher_can_merge_pending_pre_plan_orders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $base = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();
        $another = PrePlanOrder::query()->create([
            'order_no' => 'PO-MERGE-TEST-001',
            'cargo_category_id' => $base->cargo_category_id,
            'submitter_id' => $base->submitter_id,
            'client_name' => $base->client_name,
            'pickup_address' => $base->pickup_address,
            'pickup_contact_name' => $base->pickup_contact_name,
            'pickup_contact_phone' => $base->pickup_contact_phone,
            'dropoff_address' => $base->dropoff_address,
            'dropoff_contact_name' => $base->dropoff_contact_name,
            'dropoff_contact_phone' => $base->dropoff_contact_phone,
            'cargo_weight_kg' => 1000,
            'cargo_volume_m3' => 2.5,
            'audit_status' => $base->audit_status,
            'status' => 'pending',
        ]);
        Sanctum::actingAs($dispatcher);

        $response = $this->postJson('/api/v1/pre-plan-order/merge', [
            'ids' => [$base->id, $another->id],
        ]);

        $response->assertCreated()
            ->assertJsonPath('merged_from_ids.0', $base->id)
            ->assertJsonPath('merged_from_ids.1', $another->id);
        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $base->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('pre_plan_orders', [
            'id' => $another->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_dispatcher_can_filter_pre_plan_orders_by_trace_type(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $base = PrePlanOrder::query()
            ->where('status', 'pending')
            ->where('is_locked', false)
            ->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $weight = (float) $base->cargo_weight_kg;
        $volume = (float) $base->cargo_volume_m3;
        $this->postJson('/api/v1/pre-plan-order/split', [
            'id' => $base->id,
            'parts' => [
                ['cargo_weight_kg' => round($weight / 2, 2), 'cargo_volume_m3' => round($volume / 2, 2)],
                ['cargo_weight_kg' => round($weight - round($weight / 2, 2), 2), 'cargo_volume_m3' => round($volume - round($volume / 2, 2), 2)],
            ],
        ])->assertCreated();

        $splitResponse = $this->postJson('/api/v1/pre-plan-order/list', [
            'trace_type' => 'split',
        ])->assertOk();
        $this->assertTrue(
            collect($splitResponse->json('data'))->contains(function (array $item) use ($base): bool {
                return (int) data_get($item, 'meta.split_from_id') === (int) $base->id;
            }),
            '拆分筛选结果应包含拆分来源单据'
        );

        $splitOrders = PrePlanOrder::query()
            ->where('status', 'pending')
            ->where('audit_status', $base->audit_status)
            ->where('client_name', $base->client_name)
            ->where('pickup_address', $base->pickup_address)
            ->where('dropoff_address', $base->dropoff_address)
            ->where('cargo_category_id', $base->cargo_category_id)
            ->whereNotNull('meta->split_from_id')
            ->limit(2)
            ->pluck('id');
        $this->assertGreaterThanOrEqual(2, $splitOrders->count(), '拆分后应至少存在两条可并单数据');

        $this->postJson('/api/v1/pre-plan-order/merge', [
            'ids' => $splitOrders->values()->all(),
        ])->assertCreated();

        $mergeResponse = $this->postJson('/api/v1/pre-plan-order/list', [
            'trace_type' => 'merge',
        ])->assertOk();
        $this->assertTrue(
            collect($mergeResponse->json('data'))->contains(function (array $item): bool {
                $mergeFrom = data_get($item, 'meta.merge_from_ids');
                return is_array($mergeFrom) && count($mergeFrom) >= 2;
            }),
            '并单筛选结果应包含并单生成的新单'
        );
    }

    public function test_order_operation_history_is_recorded_in_meta(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/pre-plan-order/lock', ['id' => $order->id])->assertOk();
        $this->postJson('/api/v1/pre-plan-order/unlock', ['id' => $order->id])->assertOk();
        $this->postJson('/api/v1/pre-plan-order/void', [
            'id' => $order->id,
            'void_remark' => '测试作废',
        ])->assertOk();

        $fresh = PrePlanOrder::query()->findOrFail($order->id);
        $history = data_get($fresh->meta, 'history');
        $this->assertIsArray($history);

        $actions = collect($history)->pluck('action')->filter()->values()->all();
        $this->assertContains('dispatcher_lock', $actions);
        $this->assertContains('dispatcher_unlock', $actions);
        $this->assertContains('dispatcher_void', $actions);
    }

    public function test_cancelled_order_cannot_be_locked_or_edited(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/pre-plan-order/void', [
            'id' => $order->id,
            'void_remark' => '测试作废锁定限制',
        ])->assertOk();

        $this->postJson('/api/v1/pre-plan-order/lock', ['id' => $order->id])
            ->assertStatus(422);

        $this->postJson('/api/v1/pre-plan-order/update', [
            'id' => $order->id,
            'client_name' => '作废后更新',
        ])->assertStatus(422);
    }

    public function test_dispatcher_can_batch_audit_and_customer_can_compare_revision(): void
    {
        $this->seed(DatabaseSeeder::class);
        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $orderA = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '批量审核客户A',
            'pickup_address' => '地址A',
            'dropoff_address' => '地址B',
        ])->json();
        $orderB = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '批量审核客户B',
            'pickup_address' => '地址C',
            'dropoff_address' => '地址D',
        ])->json();

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/pre-plan-order/audit-batch-approve', [
            'ids' => [(int) $orderA['id'], (int) $orderB['id']],
            'audit_remark' => '批量审核通过',
        ])->assertOk()->assertJsonPath('approved_count', 2);

        Sanctum::actingAs($customer);
        $orderC = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '版本对比客户',
            'pickup_address' => '旧地址',
            'dropoff_address' => '卸货点',
        ])->json();
        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/pre-plan-order/audit-batch-reject', [
            'ids' => [(int) $orderC['id']],
            'audit_remark' => '地址信息待补充',
        ])->assertOk()->assertJsonPath('rejected_count', 1);

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/pre-plan-order/customer-update', [
            'id' => (int) $orderC['id'],
            'pickup_address' => '新地址（已补充）',
        ])->assertOk();
        $this->postJson('/api/v1/pre-plan-order/revision-compare', [
            'id' => (int) $orderC['id'],
        ])->assertOk()
            ->assertJsonPath('has_snapshot', true);
    }

    public function test_dispatcher_can_trigger_audit_timeout_reminder_and_get_templates(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/pre-plan-order/audit-remark-templates', [])
            ->assertOk()
            ->assertJsonStructure(['data']);

        $before = SystemMessage::query()->count();
        $response = $this->postJson('/api/v1/pre-plan-order/audit-timeout-reminder', [
            'timeout_hours' => 1,
        ])->assertOk();
        $this->assertGreaterThanOrEqual(0, (int) $response->json('timeout_count'));
        $this->assertGreaterThanOrEqual($before, SystemMessage::query()->count());
    }
}
