<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_statements', function (Blueprint $table): void {
            $table->id();
            $table->string('statement_no', 40)->unique();
            $table->string('client_name', 255);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('order_count')->default(0);
            $table->decimal('total_base_amount', 14, 2)->default(0);
            $table->decimal('total_loss_deduct_amount', 14, 2)->default(0);
            $table->decimal('total_freight_amount', 14, 2)->default(0);
            $table->enum('status', ['draft', 'confirmed', 'invoiced', 'paid'])->default('draft');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('invoiced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('remark', 255)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['client_name', 'period_start', 'period_end']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_statements');
    }
};
