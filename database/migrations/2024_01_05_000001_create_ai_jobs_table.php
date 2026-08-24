<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained()->nullOnDelete();
            
            // Job identification
            $table->uuid('job_uuid')->unique();
            $table->enum('request_type', ['generate', 'modify']);
            
            // Status tracking
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed'])->default('queued');
            $table->unsignedTinyInteger('retry_count')->default(0);
            
            // Provider info
            $table->string('provider', 50);
            $table->string('model', 100);
            
            // Request data (sanitized - no PII)
            $table->text('prompt');
            $table->json('options')->nullable();
            
            // Response data
            $table->json('result_schema')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('repair_log')->nullable();
            
            // Metrics
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            
            // Error handling (sanitized)
            $table->string('error_type', 50)->nullable();
            $table->text('error_message')->nullable();
            
            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['form_id', 'request_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
