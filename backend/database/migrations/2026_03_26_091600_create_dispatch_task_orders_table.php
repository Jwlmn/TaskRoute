<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_task_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pre_plan_order_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->timestamps();
            $table->unique(['dispatch_task_id', 'pre_plan_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_task_orders');
    }
};

