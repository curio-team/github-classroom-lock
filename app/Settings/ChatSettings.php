<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ChatSettings extends Settings
{
    /**
     * Whether the chat is available for prompting.
     */
    public bool $chat_active;

    /**
     * The maximum available chat tokens in the system, per user/per day for specific models.
     */
    public array $max_user_chat_tokens_per_model_per_day;

    public static function group(): string
    {
        return 'chat';
    }

    public static function getDefaultMaxUserChatTokensPerModelPerDay(): array
    {
        return [
            'GPT-4' => 46300,
            'GPT-3.5' => -1,
        ];
    }
}
