<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->cascadeOnDelete();
            $table->string('field_key');
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('disk')->default('local');
            $table->timestamps();

            $table->index(['form_submission_id', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_files');
    }
};
