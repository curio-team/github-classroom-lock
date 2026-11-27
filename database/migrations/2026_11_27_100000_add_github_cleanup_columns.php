<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohort_groups', function (Blueprint $table) {
            // Set when the resource was permanently deleted from GitHub after
            // grading. The identifiers are kept for the audit trail.
            $table->timestamp('team_deleted_at')->nullable();
            $table->timestamp('repository_deleted_at')->nullable();
            $table->timestamp('project_deleted_at')->nullable();
        });

        Schema::table('cohorts', function (Blueprint $table) {
            $table->boolean('cleanup_pending')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('cohort_groups', function (Blueprint $table) {
            $table->dropColumn(['team_deleted_at', 'repository_deleted_at', 'project_deleted_at']);
        });

        Schema::table('cohorts', function (Blueprint $table) {
            $table->dropColumn('cleanup_pending');
        });
    }
};
