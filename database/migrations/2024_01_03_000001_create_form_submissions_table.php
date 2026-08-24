<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->string('status')->default('completed'); // completed, partial
            $table->string('submission_token', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['form_id', 'submitted_at']);
            $table->index('submission_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
