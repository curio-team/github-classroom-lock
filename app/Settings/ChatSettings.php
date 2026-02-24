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
     * The list of available models teachers have configured.
     * Each entry is an array with keys: name, model_id, token_limit.
     */
    public array $models;

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

    /**
     * Returns the default models list used during initial settings setup.
     */
    public static function getDefaultModels(): array
    {
        return [
            ['name' => 'mini', 'model_id' => 'gpt-4o-mini', 'token_limit' => -1],
            ['name' => 'advanced', 'model_id' => 'gpt-4o', 'token_limit' => 46300],
        ];
    }

    /**
     * Returns the max tokens per model per day for all configured models.
     */
    public function getAllMaxUserChatTokensPerModelPerDay(): array
    {
        $limits = [];

        foreach ($this->models as $model) {
            $limits[$model['name']] = (int) $model['token_limit'];
        }

        return $limits;
    }
}
