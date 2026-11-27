<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohort_groups', function (Blueprint $table) {
            // GitHub unassigns the team members from the repository's issues
            // when locking revokes their access. Who was assigned to what is
            // stored here ({issue number: [login, ...]}) until unlocking has
            // assigned them again.
            $table->json('assignment_snapshot')->nullable();
            $table->text('assignment_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cohort_groups', function (Blueprint $table) {
            $table->dropColumn(['assignment_snapshot', 'assignment_error']);
        });
    }
};
