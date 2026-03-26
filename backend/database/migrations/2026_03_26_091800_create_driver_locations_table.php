<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('dispatch_task_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('lng', 11, 7);
            $table->decimal('lat', 10, 7);
            $table->decimal('speed_kmh', 8, 2)->nullable();
            $table->timestamp('located_at');
            $table->timestamps();
            $table->index(['driver_id', 'located_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_locations');
    }
};

