<?php

use App\Settings\ChatSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('chat.chat_active', true);
        $this->migrator->add('chat.max_user_chat_tokens_per_model_per_day', ChatSettings::getDefaultMaxUserChatTokensPerModelPerDay());
    }
};
