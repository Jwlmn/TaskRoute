<?php

namespace Tests\Feature;

use App\Models\PrePlanOrder;
use App\Models\SystemMessage;
use App\Models\User;
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
}
