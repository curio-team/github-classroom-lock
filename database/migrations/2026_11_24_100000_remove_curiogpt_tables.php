<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CurioGPT moved to gpt.curio.codes. Remove its leftovers from existing
 * databases. (Fresh installs never create these, hence the guards.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('chat_logs');
        Schema::dropIfExists('settings');

        foreach (['chats_remaining', 'chat_limits_reset'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: the chat feature has been removed.
    }
};
