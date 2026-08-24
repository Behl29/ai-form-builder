<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Forms table indexes
        Schema::table('forms', function (Blueprint $table) {
            // For listing forms with ordering
            $table->index(['tenant_id', 'updated_at'], 'forms_tenant_updated_idx');
            // For public form lookup
            $table->index(['slug', 'status'], 'forms_slug_status_idx');
        });

        // Form versions table indexes
        Schema::table('form_versions', function (Blueprint $table) {
            // For version history queries
            $table->index(['form_id', 'version_number'], 'form_versions_form_version_idx');
            // For finding published versions
            $table->index(['form_id', 'is_published'], 'form_versions_form_published_idx');
        });

        // Form submissions table indexes
        Schema::table('form_submissions', function (Blueprint $table) {
            // For duplicate detection
            $table->index(['form_id', 'ip_address', 'submitted_at'], 'form_submissions_duplicate_idx');
            // For version-specific exports
            $table->index(['form_id', 'form_version_id'], 'form_submissions_form_version_idx');
        });

        // Submission files table indexes
        Schema::table('submission_files', function (Blueprint $table) {
            // For file lookups by submission
            $table->index(['form_submission_id', 'field_key'], 'submission_files_submission_field_idx');
        });

        // AI jobs table indexes
        Schema::table('ai_jobs', function (Blueprint $table) {
            // For user job listing
            $table->index(['tenant_id', 'user_id', 'created_at'], 'ai_jobs_tenant_user_created_idx');
            // For job status lookup
            $table->index(['job_uuid', 'tenant_id'], 'ai_jobs_uuid_tenant_idx');
        });

        // Import jobs table indexes
        Schema::table('import_jobs', function (Blueprint $table) {
            // For tenant job listing
            $table->index(['tenant_id', 'created_at'], 'import_jobs_tenant_created_idx');
            // For job status lookup
            $table->index(['job_uuid', 'tenant_id'], 'import_jobs_uuid_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropIndex('forms_tenant_updated_idx');
            $table->dropIndex('forms_slug_status_idx');
        });

        Schema::table('form_versions', function (Blueprint $table) {
            $table->dropIndex('form_versions_form_version_idx');
            $table->dropIndex('form_versions_form_published_idx');
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropIndex('form_submissions_duplicate_idx');
            $table->dropIndex('form_submissions_form_version_idx');
        });

        Schema::table('submission_files', function (Blueprint $table) {
            $table->dropIndex('submission_files_submission_field_idx');
        });

        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropIndex('ai_jobs_tenant_user_created_idx');
            $table->dropIndex('ai_jobs_uuid_tenant_idx');
        });

        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropIndex('import_jobs_tenant_created_idx');
            $table->dropIndex('import_jobs_uuid_tenant_idx');
        });
    }
};
