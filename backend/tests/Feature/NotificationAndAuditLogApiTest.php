<?php

namespace Tests\Feature;

use App\Models\PrePlanOrder;
use App\Models\DispatchTask;
use App\Models\SystemMessage;
use App\Models\User;
use App\Models\CargoCategory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAndAuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_filter_pin_and_batch_read_messages(): void
    {
        $this->seed(DatabaseSeeder::class);
        $customer = User::query()->where('account', 'customer')->firstOrFail();
        Sanctum::actingAs($customer);

        $msg = SystemMessage::query()->where('user_id', $customer->id)->firstOrFail();
        $this->postJson('/api/v1/message/pin', ['id' => $msg->id, 'is_pinned' => true])
            ->assertOk()
            ->assertJsonPath('is_pinned', true);

        $list = $this->postJson('/api/v1/message/list', [
            'pinned_only' => true,
        ])->assertOk();
        $this->assertGreaterThanOrEqual(1, count($list->json('data')));

        $ids = collect($list->json('data'))->pluck('id')->take(2)->values()->all();
        $this->postJson('/api/v1/message/read-batch', ['ids' => $ids])
            ->assertOk();
    }

    public function test_dispatcher_can_query_pre_plan_order_audit_logs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $order = PrePlanOrder::query()->where('status', 'pending')->firstOrFail();
        $this->postJson('/api/v1/pre-plan-order/lock', ['id' => $order->id])->assertOk();

        $response = $this->postJson('/api/v1/pre-plan-order/audit-log-list', [
            'action' => 'dispatcher_lock',
            'keyword' => $order->order_no,
        ])->assertOk();

        $this->assertGreaterThanOrEqual(1, (int) $response->json('total'));
    }

    public function test_message_list_applies_data_scope_on_meta_related_orders(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteA = \App\Models\LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteB = \App\Models\LogisticsSite::query()->where('id', '!=', $siteA->id)->orderBy('id')->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteA->id]],
        ])->save();
        Sanctum::actingAs($dispatcher);

        $categoryId = (int) CargoCategory::query()->value('id');
        $inScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-SCOPE-IN-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围内客户',
            'pickup_site_id' => (int) $siteA->id,
            'pickup_address' => '范围内装货地',
            'dropoff_site_id' => (int) $siteA->id,
            'dropoff_address' => '范围内卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);
        $outScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-SCOPE-OUT-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围外客户',
            'pickup_site_id' => (int) $siteB->id,
            'pickup_address' => '范围外装货地',
            'dropoff_site_id' => (int) $siteB->id,
            'dropoff_address' => '范围外卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

        SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '范围内消息',
            'content' => '应可见',
            'meta' => ['order_id' => (int) $inScopeOrder->id],
        ]);
        SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '范围外消息',
            'content' => '不应可见',
            'meta' => ['order_id' => (int) $outScopeOrder->id],
        ]);

        $response = $this->postJson('/api/v1/message/list', [])->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->values()->all();

        $this->assertContains('范围内消息', $titles);
        $this->assertNotContains('范围外消息', $titles);
    }

    public function test_message_list_applies_data_scope_on_task_and_site_meta(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteA = \App\Models\LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteB = \App\Models\LogisticsSite::query()->where('id', '!=', $siteA->id)->orderBy('id')->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteA->id]],
        ])->save();
        Sanctum::actingAs($dispatcher);

        $categoryId = (int) CargoCategory::query()->value('id');
        $inScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-TASK-IN-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围内任务订单',
            'pickup_site_id' => (int) $siteA->id,
            'pickup_address' => '范围内装货地',
            'dropoff_site_id' => (int) $siteA->id,
            'dropoff_address' => '范围内卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);
        $outScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-TASK-OUT-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围外任务订单',
            'pickup_site_id' => (int) $siteB->id,
            'pickup_address' => '范围外装货地',
            'dropoff_site_id' => (int) $siteB->id,
            'dropoff_address' => '范围外卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

        $inScopeTask = DispatchTask::query()->create([
            'task_no' => 'DT-MSG-TASK-IN-001',
            'status' => 'assigned',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);
        $outScopeTask = DispatchTask::query()->create([
            'task_no' => 'DT-MSG-TASK-OUT-001',
            'status' => 'assigned',
            'dispatch_mode' => 'single_vehicle_single_order',
        ]);
        $inScopeTask->orders()->sync([$inScopeOrder->id => ['sequence' => 1]]);
        $outScopeTask->orders()->sync([$outScopeOrder->id => ['sequence' => 1]]);

        SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '任务范围内消息',
            'content' => '应可见',
            'meta' => ['task_id' => (int) $inScopeTask->id],
        ]);
        SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '任务范围外消息',
            'content' => '不应可见',
            'meta' => ['task_id' => (int) $outScopeTask->id],
        ]);
        SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '站点范围外消息',
            'content' => '不应可见',
            'meta' => ['site_ids' => [(int) $siteB->id]],
        ]);

        $response = $this->postJson('/api/v1/message/list', [])->assertOk();
        $titles = collect($response->json('data'))->pluck('title')->values()->all();

        $this->assertContains('任务范围内消息', $titles);
        $this->assertNotContains('任务范围外消息', $titles);
        $this->assertNotContains('站点范围外消息', $titles);
    }

    public function test_message_list_pagination_totals_are_consistent_after_scope_filter(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteA = \App\Models\LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteB = \App\Models\LogisticsSite::query()->where('id', '!=', $siteA->id)->orderBy('id')->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteA->id]],
        ])->save();
        Sanctum::actingAs($dispatcher);

        $categoryId = (int) CargoCategory::query()->value('id');
        foreach (range(1, 21) as $index) {
            $order = PrePlanOrder::query()->create([
                'order_no' => sprintf('PO-MSG-PAGE-IN-%03d', $index),
                'cargo_category_id' => $categoryId,
                'client_name' => '范围内分页客户',
                'pickup_site_id' => (int) $siteA->id,
                'pickup_address' => '范围内装货地',
                'dropoff_site_id' => (int) $siteA->id,
                'dropoff_address' => '范围内卸货地',
                'status' => 'pending',
                'audit_status' => 'approved',
            ]);
            SystemMessage::query()->create([
                'user_id' => (int) $dispatcher->id,
                'message_type' => 'audit_notice',
                'title' => sprintf('分页范围内消息%03d', $index),
                'content' => '应可见',
                'meta' => ['order_id' => (int) $order->id],
            ]);
        }
        foreach (range(1, 8) as $index) {
            $order = PrePlanOrder::query()->create([
                'order_no' => sprintf('PO-MSG-PAGE-OUT-%03d', $index),
                'cargo_category_id' => $categoryId,
                'client_name' => '范围外分页客户',
                'pickup_site_id' => (int) $siteB->id,
                'pickup_address' => '范围外装货地',
                'dropoff_site_id' => (int) $siteB->id,
                'dropoff_address' => '范围外卸货地',
                'status' => 'pending',
                'audit_status' => 'approved',
            ]);
            SystemMessage::query()->create([
                'user_id' => (int) $dispatcher->id,
                'message_type' => 'audit_notice',
                'title' => sprintf('分页范围外消息%03d', $index),
                'content' => '不应可见',
                'meta' => ['order_id' => (int) $order->id],
            ]);
        }

        $page1 = $this->postJson('/api/v1/message/list', ['page' => 1])->assertOk();
        $page2 = $this->postJson('/api/v1/message/list', ['page' => 2])->assertOk();

        $this->assertSame(21, (int) $page1->json('total'));
        $this->assertSame(1, (int) $page1->json('current_page'));
        $this->assertCount(20, $page1->json('data'));
        $this->assertSame(21, (int) $page2->json('total'));
        $this->assertSame(2, (int) $page2->json('current_page'));
        $this->assertCount(1, $page2->json('data'));

        $allTitles = collect(array_merge($page1->json('data') ?? [], $page2->json('data') ?? []))
            ->pluck('title')
            ->values()
            ->all();
        $this->assertTrue(collect($allTitles)->every(fn ($title) => str_contains((string) $title, '分页范围内消息')));
    }

    public function test_message_actions_respect_data_scope_after_filtering(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteA = \App\Models\LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteB = \App\Models\LogisticsSite::query()->where('id', '!=', $siteA->id)->orderBy('id')->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteA->id]],
        ])->save();
        Sanctum::actingAs($dispatcher);

        $categoryId = (int) CargoCategory::query()->value('id');
        $inScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-ACTION-IN-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围内消息动作客户',
            'pickup_site_id' => (int) $siteA->id,
            'pickup_address' => '范围内装货地',
            'dropoff_site_id' => (int) $siteA->id,
            'dropoff_address' => '范围内卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);
        $outScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-ACTION-OUT-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围外消息动作客户',
            'pickup_site_id' => (int) $siteB->id,
            'pickup_address' => '范围外装货地',
            'dropoff_site_id' => (int) $siteB->id,
            'dropoff_address' => '范围外卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

        $inScopeMessage = SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '范围内动作消息',
            'content' => '应可操作',
            'meta' => ['order_id' => (int) $inScopeOrder->id],
        ]);
        $outScopeMessage = SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '范围外动作消息',
            'content' => '不应可操作',
            'meta' => ['order_id' => (int) $outScopeOrder->id],
        ]);

        $this->postJson('/api/v1/message/read', ['id' => $inScopeMessage->id])
            ->assertOk()
            ->assertJsonPath('id', $inScopeMessage->id);
        $this->assertDatabaseHas('system_messages', [
            'id' => $inScopeMessage->id,
        ]);
        $this->assertNotNull(SystemMessage::query()->findOrFail($inScopeMessage->id)->read_at);

        $this->postJson('/api/v1/message/read', ['id' => $outScopeMessage->id])
            ->assertNotFound();
        $this->assertNull(SystemMessage::query()->findOrFail($outScopeMessage->id)->read_at);

        $this->postJson('/api/v1/message/pin', [
            'id' => $outScopeMessage->id,
            'is_pinned' => true,
        ])->assertNotFound();
        $this->assertFalse((bool) SystemMessage::query()->findOrFail($outScopeMessage->id)->is_pinned);

        $batchResponse = $this->postJson('/api/v1/message/read-batch', [
            'ids' => [$inScopeMessage->id, $outScopeMessage->id],
        ])->assertOk();
        $batchResponse->assertJsonPath('updated_count', 0);
    }

    public function test_message_batch_read_only_updates_accessible_unread_messages_and_pin_allows_accessible_message(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $siteA = \App\Models\LogisticsSite::query()->orderBy('id')->firstOrFail();
        $siteB = \App\Models\LogisticsSite::query()->where('id', '!=', $siteA->id)->orderBy('id')->firstOrFail();
        $dispatcher->forceFill([
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [(int) $siteA->id]],
        ])->save();
        Sanctum::actingAs($dispatcher);

        $categoryId = (int) CargoCategory::query()->value('id');
        $inScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-MIX-IN-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围内混合客户',
            'pickup_site_id' => (int) $siteA->id,
            'pickup_address' => '范围内装货地',
            'dropoff_site_id' => (int) $siteA->id,
            'dropoff_address' => '范围内卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);
        $outScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-MSG-MIX-OUT-001',
            'cargo_category_id' => $categoryId,
            'client_name' => '范围外混合客户',
            'pickup_site_id' => (int) $siteB->id,
            'pickup_address' => '范围外装货地',
            'dropoff_site_id' => (int) $siteB->id,
            'dropoff_address' => '范围外卸货地',
            'status' => 'pending',
            'audit_status' => 'approved',
        ]);

        $accessibleMessage = SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '可读可置顶消息',
            'content' => '范围内',
            'meta' => ['order_id' => (int) $inScopeOrder->id],
        ]);
        $inaccessibleMessage = SystemMessage::query()->create([
            'user_id' => (int) $dispatcher->id,
            'message_type' => 'audit_notice',
            'title' => '不可读消息',
            'content' => '范围外',
            'meta' => ['order_id' => (int) $outScopeOrder->id],
        ]);

        $this->postJson('/api/v1/message/pin', [
            'id' => $accessibleMessage->id,
            'is_pinned' => true,
        ])->assertOk()
            ->assertJsonPath('id', $accessibleMessage->id)
            ->assertJsonPath('is_pinned', true);
        $this->assertTrue((bool) SystemMessage::query()->findOrFail($accessibleMessage->id)->is_pinned);

        $batchResponse = $this->postJson('/api/v1/message/read-batch', [
            'ids' => [$accessibleMessage->id, $inaccessibleMessage->id],
        ])->assertOk();
        $batchResponse->assertJsonPath('updated_count', 1);

        $this->assertNotNull(SystemMessage::query()->findOrFail($accessibleMessage->id)->read_at);
        $this->assertNull(SystemMessage::query()->findOrFail($inaccessibleMessage->id)->read_at);
    }

    public function test_customer_audit_messages_include_order_context_meta(): void
    {
        $this->seed(DatabaseSeeder::class);

        $customer = User::query()->where('account', 'customer')->firstOrFail();
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();

        Sanctum::actingAs($customer);
        $submitResponse = $this->postJson('/api/v1/pre-plan-order/customer-submit', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '通知上下文客户',
            'pickup_address' => '通知装货地',
            'dropoff_address' => '通知卸货地',
        ])->assertCreated();

        $orderId = (int) $submitResponse->json('id');
        $orderNo = (string) $submitResponse->json('order_no');

        Sanctum::actingAs($dispatcher);
        $this->postJson('/api/v1/pre-plan-order/audit-reject', [
            'id' => $orderId,
            'audit_remark' => '请补充卸货联系人',
        ])->assertOk();

        Sanctum::actingAs($customer);
        $listResponse = $this->postJson('/api/v1/message/list', [
            'unread_only' => true,
            'message_type' => 'audit_notice',
        ])->assertOk();

        $message = collect($listResponse->json('data'))
            ->first(fn (array $item): bool => (int) data_get($item, 'meta.order_id') === $orderId);

        $this->assertNotNull($message, '客户应能收到关联当前订单的审核通知');
        $this->assertSame($orderNo, data_get($message, 'meta.order_no'));
        $this->assertSame('rejected', data_get($message, 'meta.audit_status'));
        $this->assertSame($orderId, (int) data_get($message, 'meta.order_id'));
    }
}
