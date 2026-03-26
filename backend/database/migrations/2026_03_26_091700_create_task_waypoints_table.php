<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_waypoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_task_id')->constrained()->cascadeOnDelete();
            $table->enum('node_type', ['pickup', 'dropoff', 'checkpoint', 'finish']);
            $table->unsignedInteger('sequence')->default(1);
            $table->string('address');
            $table->decimal('lng', 11, 7)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->enum('status', ['pending', 'arrived', 'completed'])->default('pending');
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_waypoints');
    }
};

