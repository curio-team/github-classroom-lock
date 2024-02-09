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
     * The maximum available chat tokens in the system.
     */
    public int $max_chat_tokens;

    /**
     * The amount of chat tokens used in the system.
     */
    public int $used_chat_tokens;

    public static function group(): string
    {
        return 'chat';
    }

    /**
     * Chat limit per hour for each model in situations where we're
     * not running out of chat tokens.
     */
    private static function chatsPerHour(): array
    {
        return [
            'gpt-4' => 20,
            'gpt-3.5-turbo' => -1, // Unlimited
        ];
    }

    /**
     * Gets the chat limits, with respect to the available chat tokens.
     */
    public static function getChatPerHour(): array
    {
        $settings = app(ChatSettings::class);
        $normalLimits = self::chatsPerHour();

        // TODO: Somehow keep track of how many tokens are normal to have been used
        // TODO: For this we need to know how many days of use have past and are left

        // Return the regular limits for now.
        return $normalLimits;
    }

}
