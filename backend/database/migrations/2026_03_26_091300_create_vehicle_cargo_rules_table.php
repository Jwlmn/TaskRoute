<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_cargo_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cargo_category_id')->constrained()->cascadeOnDelete();
            $table->enum('rule_type', ['allow', 'deny'])->default('allow');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['vehicle_id', 'cargo_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_cargo_rules');
    }
};

