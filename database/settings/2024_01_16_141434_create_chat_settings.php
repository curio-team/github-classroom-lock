<?php

use App\Settings\ChatSettings;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('chat.chat_active', true);
        $this->migrator->add('chat.max_user_chat_tokens_per_model_per_day', ChatSettings::getDefaultMaxUserChatTokensPerModelPerDay());
        $this->migrator->add('chat.chat_password', null);
        $this->migrator->add('chat.summarization_enabled', false);
    }
};
