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
            $table->string('client_name');
            $table->string('pickup_address');
            $table->string('dropoff_address');
            $table->decimal('cargo_weight_kg', 10, 2)->default(0);
            $table->decimal('cargo_volume_m3', 10, 2)->default(0);
            $table->timestamp('expected_pickup_at')->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
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

