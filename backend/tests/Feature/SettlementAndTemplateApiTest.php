<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\FreightRateTemplate;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\SettlementStatement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettlementAndTemplateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_can_crud_freight_template(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();
        $site = LogisticsSite::query()->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $create = $this->postJson('/api/v1/freight-template/create', [
            'name' => '测试模板A',
            'client_name' => '测试客户',
            'cargo_category_id' => $cargo->id,
            'pickup_site_id' => $site->id,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 9.5,
            'loss_allowance_kg' => 120,
            'loss_deduct_unit_price' => 1.1,
            'priority' => 500,
            'is_active' => true,
        ])->assertCreated();

        $id = (int) $create->json('id');
        $this->postJson('/api/v1/freight-template/list', ['keyword' => '测试模板A'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);

        $this->postJson('/api/v1/freight-template/update', [
            'id' => $id,
            'freight_unit_price' => 10.2,
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('freight_unit_price', 10.2)
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('pickup_site.id', $site->id);
    }

    public function test_dispatcher_cannot_create_invalid_freight_template_scheme_payload(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/freight-template/create', [
            'name' => '按趟缺少趟数',
            'freight_calc_scheme' => 'by_trip',
            'freight_unit_price' => 300,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['freight_trip_count']);

        $this->postJson('/api/v1/freight-template/create', [
            'name' => '按重量缺少单价',
            'freight_calc_scheme' => 'by_weight',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['freight_unit_price']);
    }

    public function test_dispatcher_switching_template_to_trip_scheme_requires_trip_count(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $template = $this->postJson('/api/v1/freight-template/create', [
            'name' => '切换运价模板',
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 20,
        ])->assertCreated();

        $this->postJson('/api/v1/freight-template/update', [
            'id' => (int) $template->json('id'),
            'freight_calc_scheme' => 'by_trip',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['freight_trip_count']);
    }

    public function test_site_scoped_dispatcher_can_only_manage_in_scope_freight_templates(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::factory()->create([
            'account' => 'template-scope-dispatcher',
            'name' => '模板范围调度员',
            'role' => 'dispatcher',
            'status' => 'active',
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [1]],
            'password' => bcrypt('password'),
        ]);
        $dispatcher->syncRoleAndPermissions();

        $cargo = CargoCategory::query()->firstOrFail();
        $siteIn = LogisticsSite::query()->findOrFail(1);
        $siteOut = LogisticsSite::query()->where('id', '!=', $siteIn->id)->orderBy('id')->firstOrFail();

        $inScopeTemplate = FreightRateTemplate::query()->create([
            'name' => '范围内模板',
            'cargo_category_id' => $cargo->id,
            'pickup_site_id' => $siteIn->id,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 12,
            'priority' => 200,
            'is_active' => true,
        ]);
        $outScopeTemplate = FreightRateTemplate::query()->create([
            'name' => '范围外模板',
            'cargo_category_id' => $cargo->id,
            'pickup_site_id' => $siteOut->id,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 15,
            'priority' => 200,
            'is_active' => true,
        ]);

        Sanctum::actingAs($dispatcher);

        $listResponse = $this->postJson('/api/v1/freight-template/list', [])->assertOk();
        $templateIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertContains($inScopeTemplate->id, $templateIds);
        $this->assertNotContains($outScopeTemplate->id, $templateIds);

        $this->postJson('/api/v1/freight-template/detail', [
            'id' => $outScopeTemplate->id,
        ])->assertNotFound();

        $this->postJson('/api/v1/freight-template/create', [
            'name' => '越权模板',
            'cargo_category_id' => $cargo->id,
            'pickup_site_id' => $siteOut->id,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 20,
        ])->assertStatus(403);

        $this->postJson('/api/v1/freight-template/update', [
            'id' => $outScopeTemplate->id,
            'is_active' => false,
        ])->assertNotFound();
    }

    public function test_pre_plan_order_prefers_site_specific_freight_template(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();
        $site = LogisticsSite::query()->findOrFail(1);
        Sanctum::actingAs($dispatcher);

        $globalTemplate = FreightRateTemplate::query()->create([
            'name' => '全局模板',
            'client_name' => '站点匹配客户',
            'cargo_category_id' => $cargo->id,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 11,
            'priority' => 100,
            'is_active' => true,
        ]);
        $siteTemplate = FreightRateTemplate::query()->create([
            'name' => '站点模板',
            'client_name' => '站点匹配客户',
            'cargo_category_id' => $cargo->id,
            'pickup_site_id' => $site->id,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 18,
            'priority' => 100,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/pre-plan-order/create', [
            'cargo_category_id' => $cargo->id,
            'client_name' => '站点匹配客户',
            'pickup_site_id' => $site->id,
            'pickup_address' => $site->address,
            'dropoff_site_id' => $site->id,
            'dropoff_address' => '测试卸货地',
        ])->assertCreated();

        $this->assertSame(18.0, (float) $response->json('freight_unit_price'));
        $this->assertSame('by_weight', $response->json('freight_calc_scheme'));
        $this->assertSame($siteTemplate->id, (int) $response->json('meta.freight_template_id'));
        $this->assertNotSame($globalTemplate->id, (int) $response->json('meta.freight_template_id'));
    }

    public function test_dispatcher_can_preview_matched_freight_template(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        $cargo = CargoCategory::query()->firstOrFail();
        $site = LogisticsSite::query()->findOrFail(1);
        Sanctum::actingAs($dispatcher);

        $siteTemplate = FreightRateTemplate::query()->create([
            'name' => '预览站点模板',
            'client_name' => '预览客户',
            'cargo_category_id' => $cargo->id,
            'pickup_site_id' => $site->id,
            'freight_calc_scheme' => 'by_weight',
            'freight_unit_price' => 21,
            'priority' => 300,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/freight-template/match-preview', [
            'client_name' => '预览客户',
            'cargo_category_id' => $cargo->id,
            'pickup_site_id' => $site->id,
            'pickup_address' => $site->address,
            'dropoff_address' => '任意卸货地',
        ])->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('template.id', $siteTemplate->id)
            ->assertJsonPath('template.name', '预览站点模板');

        $this->postJson('/api/v1/freight-template/match-preview', [
            'client_name' => '不存在的客户',
            'cargo_category_id' => $cargo->id,
            'pickup_address' => '未知装货地',
            'dropoff_address' => '未知卸货地',
        ])->assertOk()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('template', null);
    }

    public function test_dispatcher_can_create_settlement_statement_from_completed_orders(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        PrePlanOrder::query()->create([
            'order_no' => 'PO-SETTLE-TEST-001',
            'cargo_category_id' => CargoCategory::query()->firstOrFail()->id,
            'submitter_id' => $dispatcher->id,
            'client_name' => '结算客户A',
            'pickup_address' => '装货地A',
            'dropoff_address' => '卸货地A',
            'status' => 'completed',
            'audit_status' => 'approved',
            'freight_base_amount' => 1000,
            'freight_loss_deduct_amount' => 50,
            'freight_amount' => 950,
            'freight_calculated_at' => now()->subDay(),
        ]);

        $create = $this->postJson('/api/v1/settlement/create', [
            'client_name' => '结算客户A',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
            'remark' => '测试结算',
        ])->assertCreated();

        $statementId = (int) $create->json('id');
        $this->assertGreaterThan(0, $statementId);
        $this->assertSame(1, (int) $create->json('order_count'));
        $this->assertSame(950.0, (float) $create->json('total_freight_amount'));

        $this->postJson('/api/v1/settlement/list', ['client_name' => '结算客户A'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $statementId);

        $this->postJson('/api/v1/settlement/detail', ['id' => $statementId])
            ->assertOk()
            ->assertJsonPath('orders.0.order_no', 'PO-SETTLE-TEST-001');
    }

    public function test_dispatcher_cannot_create_empty_settlement_statement(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $this->postJson('/api/v1/settlement/create', [
            'client_name' => '不存在的结算客户',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['client_name']);
    }

    public function test_site_scoped_dispatcher_can_only_settle_and_access_in_scope_orders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $dispatcher = User::factory()->create([
            'account' => 'settlement-scope-dispatcher',
            'name' => '范围调度员',
            'role' => 'dispatcher',
            'status' => 'active',
            'data_scope_type' => 'site',
            'data_scope' => ['site_ids' => [1]],
            'password' => bcrypt('password'),
        ]);
        $dispatcher->syncRoleAndPermissions();

        $cargoCategory = CargoCategory::query()->firstOrFail();
        $siteIn = LogisticsSite::query()->findOrFail(1);
        $siteOut = LogisticsSite::query()->where('id', '!=', $siteIn->id)->orderBy('id')->firstOrFail();

        $inScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-SETTLE-IN-SCOPE',
            'cargo_category_id' => $cargoCategory->id,
            'submitter_id' => $dispatcher->id,
            'client_name' => '范围客户A',
            'pickup_site_id' => $siteIn->id,
            'pickup_address' => '范围内装货地',
            'dropoff_site_id' => $siteIn->id,
            'dropoff_address' => '范围内卸货地',
            'status' => 'completed',
            'audit_status' => 'approved',
            'freight_base_amount' => 800,
            'freight_loss_deduct_amount' => 50,
            'freight_amount' => 750,
            'freight_calculated_at' => now()->subDay(),
        ]);

        $outScopeOrder = PrePlanOrder::query()->create([
            'order_no' => 'PO-SETTLE-OUT-SCOPE',
            'cargo_category_id' => $cargoCategory->id,
            'submitter_id' => $dispatcher->id,
            'client_name' => '范围客户A',
            'pickup_site_id' => $siteOut->id,
            'pickup_address' => '范围外装货地',
            'dropoff_site_id' => $siteOut->id,
            'dropoff_address' => '范围外卸货地',
            'status' => 'completed',
            'audit_status' => 'approved',
            'freight_base_amount' => 1200,
            'freight_loss_deduct_amount' => 0,
            'freight_amount' => 1200,
            'freight_calculated_at' => now()->subDay(),
        ]);

        $outScopeStatement = SettlementStatement::query()->create([
            'statement_no' => 'ST-OUT-SCOPE-001',
            'client_name' => '范围客户A',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
            'order_count' => 1,
            'total_base_amount' => 1200,
            'total_loss_deduct_amount' => 0,
            'total_freight_amount' => 1200,
            'status' => 'draft',
            'created_by' => User::query()->where('account', 'dispatcher')->firstOrFail()->id,
            'meta' => [
                'order_ids' => [$outScopeOrder->id],
            ],
        ]);

        Sanctum::actingAs($dispatcher);

        $create = $this->postJson('/api/v1/settlement/create', [
            'client_name' => '范围客户A',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
        ])->assertCreated();

        $statementId = (int) $create->json('id');
        $this->assertSame(1, (int) $create->json('order_count'));
        $this->assertSame(750.0, (float) $create->json('total_freight_amount'));

        $this->postJson('/api/v1/settlement/detail', ['id' => $statementId])
            ->assertOk()
            ->assertJsonCount(1, 'orders')
            ->assertJsonPath('orders.0.id', $inScopeOrder->id);

        $listResponse = $this->postJson('/api/v1/settlement/list', [
            'client_name' => '范围客户A',
        ])->assertOk();
        $statementIds = collect($listResponse->json('data'))->pluck('id')->all();
        $this->assertContains($statementId, $statementIds);
        $this->assertNotContains($outScopeStatement->id, $statementIds);

        $this->postJson('/api/v1/settlement/detail', ['id' => $outScopeStatement->id])
            ->assertNotFound();

        $this->postJson('/api/v1/settlement/update', [
            'id' => $outScopeStatement->id,
            'remark' => '越权更新尝试',
        ])->assertNotFound();
    }

    public function test_settlement_status_must_follow_ordered_flow(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $statement = SettlementStatement::query()->create([
            'statement_no' => 'ST-FLOW-001',
            'client_name' => '状态流转客户',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
            'order_count' => 1,
            'total_base_amount' => 1000,
            'total_loss_deduct_amount' => 0,
            'total_freight_amount' => 1000,
            'status' => 'draft',
            'created_by' => $dispatcher->id,
            'meta' => ['order_ids' => []],
        ]);

        $confirmResponse = $this->postJson('/api/v1/settlement/update', [
            'id' => $statement->id,
            'status' => 'confirmed',
        ])->assertOk()
            ->assertJsonPath('status', 'confirmed');

        $this->assertNotNull($confirmResponse->json('confirmed_at'));
        $this->assertSame($dispatcher->id, (int) $confirmResponse->json('confirmed_by'));

        $this->postJson('/api/v1/settlement/update', [
            'id' => $statement->id,
            'status' => 'paid',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->postJson('/api/v1/settlement/update', [
            'id' => $statement->id,
            'status' => 'invoiced',
        ])->assertOk()
            ->assertJsonPath('status', 'invoiced')
            ->assertJsonPath('invoiced_by', $dispatcher->id);

        $paidResponse = $this->postJson('/api/v1/settlement/update', [
            'id' => $statement->id,
            'status' => 'paid',
        ])->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('paid_by', $dispatcher->id);

        $this->assertNotNull($paidResponse->json('invoiced_at'));
        $this->assertNotNull($paidResponse->json('paid_at'));

        $this->postJson('/api/v1/settlement/update', [
            'id' => $statement->id,
            'status' => 'draft',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_settlement_repeat_confirm_keeps_original_confirmation_info(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $statement = SettlementStatement::query()->create([
            'statement_no' => 'ST-FLOW-002',
            'client_name' => '确认幂等客户',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
            'order_count' => 1,
            'total_base_amount' => 1000,
            'total_loss_deduct_amount' => 0,
            'total_freight_amount' => 1000,
            'status' => 'confirmed',
            'created_by' => $dispatcher->id,
            'confirmed_by' => $dispatcher->id,
            'confirmed_at' => now()->subHour(),
            'meta' => ['order_ids' => []],
        ]);

        $originalConfirmedAt = $statement->confirmed_at?->toDateTimeString();

        $response = $this->postJson('/api/v1/settlement/update', [
            'id' => $statement->id,
            'status' => 'confirmed',
            'remark' => '补充备注',
        ])->assertOk()
            ->assertJsonPath('status', 'confirmed')
            ->assertJsonPath('remark', '补充备注');

        $this->assertSame($dispatcher->id, (int) $response->json('confirmed_by'));
        $this->assertSame(
            Carbon::parse($originalConfirmedAt)->toIso8601String(),
            Carbon::parse((string) $response->json('confirmed_at'))->toIso8601String()
        );
    }

    public function test_settlement_repeat_invoiced_and_paid_keep_original_audit_info(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $statement = SettlementStatement::query()->create([
            'statement_no' => 'ST-FLOW-003',
            'client_name' => '开票回款幂等客户',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
            'order_count' => 1,
            'total_base_amount' => 1000,
            'total_loss_deduct_amount' => 0,
            'total_freight_amount' => 1000,
            'status' => 'paid',
            'created_by' => $dispatcher->id,
            'confirmed_by' => $dispatcher->id,
            'confirmed_at' => now()->subHours(3),
            'invoiced_by' => $dispatcher->id,
            'invoiced_at' => now()->subHours(2),
            'paid_by' => $dispatcher->id,
            'paid_at' => now()->subHour(),
            'meta' => ['order_ids' => []],
        ]);

        $originalInvoicedAt = $statement->invoiced_at?->toDateTimeString();
        $originalPaidAt = $statement->paid_at?->toDateTimeString();

        $paidResponse = $this->postJson('/api/v1/settlement/update', [
            'id' => $statement->id,
            'status' => 'paid',
            'remark' => '再次保存',
        ])->assertOk()
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('remark', '再次保存')
            ->assertJsonPath('invoiced_by', $dispatcher->id)
            ->assertJsonPath('paid_by', $dispatcher->id);

        $this->assertSame(
            Carbon::parse($originalInvoicedAt)->toIso8601String(),
            Carbon::parse((string) $paidResponse->json('invoiced_at'))->toIso8601String()
        );
        $this->assertSame(
            Carbon::parse($originalPaidAt)->toIso8601String(),
            Carbon::parse((string) $paidResponse->json('paid_at'))->toIso8601String()
        );
    }

    public function test_settlement_detail_and_list_include_audit_operators(): void
    {
        $this->seed(DatabaseSeeder::class);
        $dispatcher = User::query()->where('role', 'dispatcher')->firstOrFail();
        Sanctum::actingAs($dispatcher);

        $statement = SettlementStatement::query()->create([
            'statement_no' => 'ST-FLOW-004',
            'client_name' => '审计展示客户',
            'period_start' => now()->subDays(2)->toDateString(),
            'period_end' => now()->toDateString(),
            'order_count' => 1,
            'total_base_amount' => 1000,
            'total_loss_deduct_amount' => 0,
            'total_freight_amount' => 1000,
            'status' => 'paid',
            'created_by' => $dispatcher->id,
            'confirmed_by' => $dispatcher->id,
            'confirmed_at' => now()->subHours(3),
            'invoiced_by' => $dispatcher->id,
            'invoiced_at' => now()->subHours(2),
            'paid_by' => $dispatcher->id,
            'paid_at' => now()->subHour(),
            'meta' => ['order_ids' => []],
        ]);

        $this->postJson('/api/v1/settlement/detail', [
            'id' => $statement->id,
        ])->assertOk()
            ->assertJsonPath('creator.id', $dispatcher->id)
            ->assertJsonPath('creator.account', $dispatcher->account)
            ->assertJsonPath('confirmer.id', $dispatcher->id)
            ->assertJsonPath('invoicer.id', $dispatcher->id)
            ->assertJsonPath('payer.id', $dispatcher->id);

        $this->postJson('/api/v1/settlement/list', [
            'client_name' => '审计展示客户',
        ])->assertOk()
            ->assertJsonPath('data.0.creator.id', $dispatcher->id)
            ->assertJsonPath('data.0.confirmer.id', $dispatcher->id)
            ->assertJsonPath('data.0.invoicer.id', $dispatcher->id)
            ->assertJsonPath('data.0.payer.id', $dispatcher->id);
    }
}
