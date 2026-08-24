<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('schema_version', 20)->default('1.0');
            $table->json('schema');
            $table->enum('change_type', ['created', 'updated', 'published', 'restored'])->default('created');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'version_number']);
            $table->index(['form_id', 'is_published']);
        });

        // Add foreign key for current_version_id after form_versions exists
        Schema::table('forms', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')
                ->on('form_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('form_versions');
    }
};
