<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('form_id')->nullable()->constrained()->onDelete('set null');
            $table->uuid('job_uuid')->unique();
            $table->string('import_type', 10); // docx, xlsx
            $table->string('status', 20)->default('queued');
            $table->string('original_filename');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->json('parsed_elements')->nullable();
            $table->json('corrected_elements')->nullable();
            $table->json('result_schema')->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('use_ai_classification')->default(false);
            $table->foreignId('ai_job_id')->nullable()->constrained('ai_jobs')->onDelete('set null');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
