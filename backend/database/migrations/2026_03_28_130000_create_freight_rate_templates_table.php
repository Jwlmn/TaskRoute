<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freight_rate_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('client_name', 255)->nullable();
            $table->foreignId('cargo_category_id')->nullable()->constrained('cargo_categories')->nullOnDelete();
            $table->string('pickup_address', 255)->nullable();
            $table->string('dropoff_address', 255)->nullable();
            $table->enum('freight_calc_scheme', ['by_weight', 'by_volume', 'by_trip']);
            $table->decimal('freight_unit_price', 12, 2)->nullable();
            $table->unsignedInteger('freight_trip_count')->nullable();
            $table->decimal('loss_allowance_kg', 12, 2)->default(0);
            $table->decimal('loss_deduct_unit_price', 12, 2)->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->string('remark', 255)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index(['client_name', 'cargo_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_rate_templates');
    }
};
