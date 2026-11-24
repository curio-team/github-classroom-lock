<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohort_groups', function (Blueprint $table) {
            // Exam lock: set while the team's access to its repository and
            // Project is revoked (members stay in the team).
            $table->timestamp('locked_at')->nullable();
            $table->boolean('lock_pending')->default(false);
            $table->text('lock_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cohort_groups', function (Blueprint $table) {
            $table->dropColumn(['locked_at', 'lock_pending', 'lock_error']);
        });
    }
};
