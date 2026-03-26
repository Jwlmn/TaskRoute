<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_sites', function (Blueprint $table): void {
            $table->id();
            $table->string('site_no')->unique();
            $table->string('name');
            $table->enum('site_type', ['pickup', 'dropoff', 'both'])->default('both');
            $table->string('contact_person')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('address');
            $table->decimal('lng', 11, 7)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_sites');
    }
};

