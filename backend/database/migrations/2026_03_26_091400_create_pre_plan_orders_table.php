<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_plan_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_no')->unique();
            $table->foreignId('cargo_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_name');
            $table->string('pickup_address');
            $table->string('pickup_contact_name', 64)->nullable();
            $table->string('pickup_contact_phone', 32)->nullable();
            $table->string('dropoff_address');
            $table->string('dropoff_contact_name', 64)->nullable();
            $table->string('dropoff_contact_phone', 32)->nullable();
            $table->decimal('cargo_weight_kg', 10, 2)->default(0);
            $table->decimal('cargo_volume_m3', 10, 2)->default(0);
            $table->enum('freight_calc_scheme', ['by_weight', 'by_volume', 'by_trip'])->nullable();
            $table->decimal('freight_unit_price', 10, 2)->nullable();
            $table->unsignedInteger('freight_trip_count')->default(1);
            $table->decimal('actual_delivered_weight_kg', 10, 2)->nullable();
            $table->decimal('loss_allowance_kg', 10, 2)->default(0);
            $table->decimal('loss_deduct_unit_price', 10, 2)->nullable();
            $table->decimal('freight_base_amount', 12, 2)->nullable();
            $table->decimal('freight_loss_deduct_amount', 12, 2)->nullable();
            $table->decimal('freight_amount', 12, 2)->nullable();
            $table->timestamp('freight_calculated_at')->nullable();
            $table->timestamp('expected_pickup_at')->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
            $table->enum('audit_status', ['pending_approval', 'approved', 'rejected'])->default('approved');
            $table->foreignId('audited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('audited_at')->nullable();
            $table->string('audit_remark', 255)->nullable();
            $table->enum('status', ['pending', 'scheduled', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_plan_orders');
    }
};
