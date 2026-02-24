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
     * The advanced (more expensive and thus limited) model to use.
     */
    public string $model_advanced;

    /**
     * Additional models that can be selected by users.
     * Each entry is an array with keys: name, model_id, token_limit.
     */
    public array $additional_models;

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
     * Returns the max tokens per model per day for all models (built-in + additional).
     */
    public function getAllMaxUserChatTokensPerModelPerDay(): array
    {
        $limits = $this->max_user_chat_tokens_per_model_per_day;

        foreach ($this->additional_models as $model) {
            $limits[$model['name']] = (int) $model['token_limit'];
        }

        return $limits;
    }
}
