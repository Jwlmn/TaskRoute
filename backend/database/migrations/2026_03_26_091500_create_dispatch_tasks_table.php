<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('task_no')->unique();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dispatcher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('dispatch_mode', [
                'single_vehicle_single_order',
                'single_vehicle_multi_order',
                'multi_vehicle_single_order',
                'multi_vehicle_multi_order',
            ])->default('single_vehicle_single_order');
            $table->enum('status', ['draft', 'assigned', 'accepted', 'in_progress', 'completed', 'cancelled'])->default('draft');
            $table->decimal('estimated_distance_km', 10, 2)->nullable();
            $table->decimal('estimated_fuel_l', 10, 2)->nullable();
            $table->json('route_meta')->nullable();
            $table->timestamp('planned_start_at')->nullable();
            $table->timestamp('planned_end_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_tasks');
    }
};

