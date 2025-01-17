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
     * The low-cost model to use.
     */
    public string $model_mini;

    /**
     * Whether to enable summarization for long chats.
     */
    public bool $summarization_enabled;

    /**
     * The advanced (more expensive and thus limited) model to use.
     */
    public string $model_advanced;

    public static function group(): string
    {
        return 'chat';
    }

    public static function getDefaultMaxUserChatTokensPerModelPerDay(): array
    {
        return [
            'advanced' => 46300,
            'mini' => -1,
        ];
    }
}
