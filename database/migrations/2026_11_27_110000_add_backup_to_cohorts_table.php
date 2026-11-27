<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cohorts', function (Blueprint $table) {
            // The latest zip with a clone of every group repository.
            // Status: null (never made), pending, ready or failed.
            $table->string('backup_status')->nullable();
            $table->string('backup_path')->nullable();
            $table->unsignedBigInteger('backup_size')->nullable();
            $table->timestamp('backup_created_at')->nullable();
            $table->text('backup_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cohorts', function (Blueprint $table) {
            $table->dropColumn(['backup_status', 'backup_path', 'backup_size', 'backup_created_at', 'backup_error']);
        });
    }
};
