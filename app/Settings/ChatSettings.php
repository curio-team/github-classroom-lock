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
     * Which password to lock the chat behind.
     */
    public ?string $chat_password;

    /**
     * The maximum available chat tokens in the system, per user/per day for specific models.
     */
    public array $max_user_chat_tokens_per_model_per_day;

    /**
     * The GPT-3 model to use.
     */
    public string $model_gpt3;

    /**
     * The GPT-4 model to use.
     */
    public string $model_gpt4;

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
