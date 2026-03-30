<?php

namespace Tests\Feature;

use App\Models\CargoCategory;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\SettlementStatement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
        Sanctum::actingAs($dispatcher);

        $create = $this->postJson('/api/v1/freight-template/create', [
            'name' => '测试模板A',
            'client_name' => '测试客户',
            'cargo_category_id' => $cargo->id,
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
            ->assertJsonPath('is_active', false);
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
}
