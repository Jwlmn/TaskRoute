<?php

namespace Database\Seeders;

use App\Models\CargoCategory;
use App\Models\LogisticsSite;
use App\Models\PrePlanOrder;
use App\Models\SystemMessage;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MockDataSeeder extends Seeder
{
    public function run(): void
    {
        // 扩展人员 mock 数据：额外司机与调度员
        User::factory()->count(2)->create([
            'role' => 'dispatcher',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);
        User::factory()->count(6)->create([
            'role' => 'driver',
            'status' => 'active',
            'password' => Hash::make('password'),
        ]);

        // 扩展资源 mock 数据：车辆、站点、货品、计划单
        CargoCategory::factory()->count(4)->create();
        for ($i = 0; $i < 8; $i++) {
            Vehicle::factory()->create(['status' => 'idle']);
        }
        LogisticsSite::factory()->count(10)->create(['status' => 'active']);
        PrePlanOrder::factory()->count(20)->create(['status' => 'pending']);

        // 客户提报审核流 mock 数据（待审核/已通过/已驳回）
        $customer = User::query()->where('account', 'customer')->first();
        $dispatcher = User::query()->where('account', 'dispatcher')->first();
        $gasoline = CargoCategory::query()->where('code', 'gasoline')->first();
        $seafood = CargoCategory::query()->where('code', 'seafood')->first();
        $vegetable = CargoCategory::query()->where('code', 'vegetable')->first();

        if ($customer && $dispatcher && $gasoline && $seafood && $vegetable) {
            $pendingOrder = PrePlanOrder::query()->updateOrCreate(
                ['order_no' => 'PO-CUSTOMER-PENDING-001'],
                [
                    'cargo_category_id' => $gasoline->id,
                    'submitter_id' => (int) $customer->id,
                    'client_name' => '客户A',
                    'pickup_address' => '上海油库客户自提A',
                    'pickup_contact_name' => '张装货',
                    'pickup_contact_phone' => '13910000001',
                    'dropoff_address' => '上海加油站客户门店A',
                    'dropoff_contact_name' => '李收货',
                    'dropoff_contact_phone' => '13910000002',
                    'cargo_weight_kg' => 30000,
                    'cargo_volume_m3' => 38.5,
                    'freight_calc_scheme' => 'by_weight',
                    'freight_unit_price' => 8.5,
                    'freight_trip_count' => 1,
                    'loss_allowance_kg' => 200,
                    'loss_deduct_unit_price' => 1.2,
                    'expected_pickup_at' => now()->addHours(6),
                    'expected_delivery_at' => now()->addHours(12),
                    'audit_status' => 'pending_approval',
                    'audited_by' => null,
                    'audited_at' => null,
                    'audit_remark' => null,
                    'status' => 'pending',
                    'meta' => ['scene' => 'oil', 'source' => 'customer_submit'],
                ]
            );

            $approvedOrder = PrePlanOrder::query()->updateOrCreate(
                ['order_no' => 'PO-CUSTOMER-APPROVED-001'],
                [
                    'cargo_category_id' => $seafood->id,
                    'submitter_id' => (int) $customer->id,
                    'client_name' => '客户A',
                    'pickup_address' => '上海冷链仓客户提货B',
                    'pickup_contact_name' => '王提货',
                    'pickup_contact_phone' => '13910000003',
                    'dropoff_address' => '上海商超门店客户B',
                    'dropoff_contact_name' => '赵收货',
                    'dropoff_contact_phone' => '13910000004',
                    'cargo_weight_kg' => 5600,
                    'cargo_volume_m3' => 22.6,
                    'freight_calc_scheme' => 'by_volume',
                    'freight_unit_price' => 65,
                    'freight_trip_count' => 1,
                    'loss_allowance_kg' => 80,
                    'loss_deduct_unit_price' => 2.0,
                    'expected_pickup_at' => now()->addHours(9),
                    'expected_delivery_at' => now()->addHours(15),
                    'audit_status' => 'approved',
                    'audited_by' => (int) $dispatcher->id,
                    'audited_at' => now()->subHours(2),
                    'audit_remark' => '资料完整，允许调度',
                    'status' => 'pending',
                    'meta' => ['scene' => 'retail', 'source' => 'customer_submit'],
                ]
            );

            $rejectedOrder = PrePlanOrder::query()->updateOrCreate(
                ['order_no' => 'PO-CUSTOMER-REJECTED-001'],
                [
                    'cargo_category_id' => $vegetable->id,
                    'submitter_id' => (int) $customer->id,
                    'client_name' => '客户A',
                    'pickup_address' => '上海分拣仓客户提货C',
                    'pickup_contact_name' => '周提货',
                    'pickup_contact_phone' => '13910000005',
                    'dropoff_address' => '上海商超门店客户C',
                    'dropoff_contact_name' => '吴收货',
                    'dropoff_contact_phone' => '13910000006',
                    'cargo_weight_kg' => 4200,
                    'cargo_volume_m3' => 16.8,
                    'freight_calc_scheme' => 'by_trip',
                    'freight_unit_price' => 1200,
                    'freight_trip_count' => 2,
                    'loss_allowance_kg' => 60,
                    'loss_deduct_unit_price' => 1.5,
                    'expected_pickup_at' => now()->addHours(10),
                    'expected_delivery_at' => now()->addHours(20),
                    'audit_status' => 'rejected',
                    'audited_by' => (int) $dispatcher->id,
                    'audited_at' => now()->subHours(1),
                    'audit_remark' => '收货联系人缺失，请补充后重提',
                    'status' => 'pending',
                    'meta' => ['scene' => 'retail', 'source' => 'customer_submit'],
                ]
            );

            SystemMessage::query()->updateOrCreate(
                [
                    'user_id' => (int) $customer->id,
                    'title' => '计划单审核通过',
                    'message_type' => 'audit_notice',
                ],
                [
                    'content' => sprintf('计划单 %s 已审核通过，备注：资料完整，允许调度', $approvedOrder->order_no),
                    'meta' => [
                        'order_id' => (int) $approvedOrder->id,
                        'order_no' => (string) $approvedOrder->order_no,
                        'audit_status' => 'approved',
                    ],
                    'read_at' => now()->subMinutes(30),
                ]
            );

            SystemMessage::query()->updateOrCreate(
                [
                    'user_id' => (int) $customer->id,
                    'title' => '计划单审核驳回',
                    'message_type' => 'audit_notice',
                ],
                [
                    'content' => sprintf('计划单 %s 被驳回，原因：收货联系人缺失，请补充后重提', $rejectedOrder->order_no),
                    'meta' => [
                        'order_id' => (int) $rejectedOrder->id,
                        'order_no' => (string) $rejectedOrder->order_no,
                        'audit_status' => 'rejected',
                    ],
                    'read_at' => null,
                ]
            );

            SystemMessage::query()->updateOrCreate(
                [
                    'user_id' => (int) $customer->id,
                    'title' => '计划单待审核提醒',
                    'message_type' => 'audit_notice',
                ],
                [
                    'content' => sprintf('计划单 %s 已提交，等待调度审核', $pendingOrder->order_no),
                    'meta' => [
                        'order_id' => (int) $pendingOrder->id,
                        'order_no' => (string) $pendingOrder->order_no,
                        'audit_status' => 'pending_approval',
                    ],
                    'read_at' => null,
                ]
            );
        }
    }
}
