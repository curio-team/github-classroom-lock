<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('chat.chat_active', true);
        $this->migrator->add('chat.max_chat_tokens', 37284480);
        $this->migrator->add('chat.used_chat_tokens', 0);
    }
};
