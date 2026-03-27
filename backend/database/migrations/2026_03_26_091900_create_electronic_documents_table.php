<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electronic_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_waypoint_id')->constrained('task_waypoints')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->enum('document_type', ['pickup_note', 'dropoff_note', 'receipt', 'signoff', 'photo', 'exception']);
            $table->string('file_path');
            $table->json('meta')->nullable();
            $table->timestamp('uploaded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_documents');
    }
};
