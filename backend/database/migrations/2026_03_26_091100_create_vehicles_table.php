<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->string('plate_number')->unique();
            $table->string('name');
            $table->string('vehicle_type');
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique('driver_id');
            $table->decimal('max_weight_kg', 10, 2)->default(0);
            $table->decimal('max_volume_m3', 10, 2)->default(0);
            $table->enum('status', ['idle', 'busy', 'maintenance'])->default('idle');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
