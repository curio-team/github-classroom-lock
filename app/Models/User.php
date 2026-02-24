<?php

namespace App\Models;

use App\Settings\ChatSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'chat_limits_reset' => 'datetime',
        'chats_remaining' => 'array',
    ];

    public function isTeacher(): bool
    {
        return $this->type === 'teacher';
    }

    /**
     * Returns the chat limits for the user.
     */
    public function getChatLimits(): array
    {
        $settings = app(\App\Settings\ChatSettings::class);
        $allLimits = $settings->getAllMaxUserChatTokensPerModelPerDay();

        if ($this->chat_limits_reset === null || $this->chat_limits_reset->isPast()) {
            $this->chats_remaining = $allLimits;
            $this->chat_limits_reset = now()->endOfDay();
            $this->save();
        }

        $remainingChats = $this->chats_remaining ?? $allLimits;

        // Ensure newly added models are included for existing users
        foreach ($allLimits as $model => $limit) {
            if (!array_key_exists($model, $remainingChats)) {
                $remainingChats[$model] = $limit;
            }
        }

        // Sort so the lowest limit model is first
        asort($remainingChats);

        return $remainingChats;
    }

    /**
     * Returns whether the user has tokens to prompt this GPT.
     */
    public function canChatWithModel(string $model): bool
    {
        $chatsRemaining = $this->getChatLimits();

        if (!array_key_exists($model, $chatsRemaining)) {
            return false;
        }

        if ($chatsRemaining[$model] === -1) {
            return true;
        }

        return $chatsRemaining[$model] > 0;
    }

    /**
     * Uses a chat token for the given model.
     */
    public function registerChatWithModel(string $model, int $tokenCount, string $prompt, string $response): void
    {
        $chatsRemaining = $this->getChatLimits();

        $this->chatLogs()->save(new ChatLog([
            'model_id' => $model,
            'prompt' => $prompt,
            'response' => $response,
        ]));

        if (!array_key_exists($model, $chatsRemaining) || $chatsRemaining[$model] === -1) {
            return;
        }

        $chatsRemaining[$model] -= $tokenCount;
        $this->chats_remaining = $chatsRemaining;
        $this->save();
    }

    /**
     *
     * Relationships
     *
     */
    public function chatLogs()
    {
        return $this->hasMany(ChatLog::class);
    }
}
