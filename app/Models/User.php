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
        $chatsRemaining = $this->chats_remaining;
        $chatLimitsReset = $this->chat_limits_reset;

        if (!$chatLimitsReset || now()->gt($chatLimitsReset)) {
            $chatsRemaining = ChatSettings::getChatPerHour();
            $this->chats_remaining = $chatsRemaining;
            $this->chat_limits_reset = now()->addHour();
            $this->save();
        }

        return $chatsRemaining;
    }

    /**
     * Returns whether the user has tokens to prompt this GPT.
     */
    public function canChatWithModel(string $model): bool
    {
        $chatsRemaining = $this->getChatLimits();

        if ($chatsRemaining[$model] === -1) {
            return true;
        }

        return $chatsRemaining[$model] > 0;
    }

    /**
     * Uses a chat token for the given model.
     */
    public function registerChatWithModel(string $model): void
    {
        $chatsRemaining = $this->getChatLimits();

        if ($chatsRemaining[$model] === -1) {
            return;
        }

        $chatsRemaining[$model]--;
        $this->chats_remaining = $chatsRemaining;
        $this->save();
    }
}
