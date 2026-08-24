<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_versions', function (Blueprint $table) {
            $table->json('change_summary')->nullable()->after('change_type');
            $table->unsignedBigInteger('restored_from_version_id')->nullable()->after('change_summary');
            $table->foreign('restored_from_version_id')
                ->references('id')
                ->on('form_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('form_versions', function (Blueprint $table) {
            $table->dropForeign(['restored_from_version_id']);
            $table->dropColumn(['change_summary', 'restored_from_version_id']);
        });
    }
};
