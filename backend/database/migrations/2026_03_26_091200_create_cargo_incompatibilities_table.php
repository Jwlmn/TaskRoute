<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cargo_incompatibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cargo_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('incompatible_with_id')->constrained('cargo_categories')->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['cargo_category_id', 'incompatible_with_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cargo_incompatibilities');
    }
};

