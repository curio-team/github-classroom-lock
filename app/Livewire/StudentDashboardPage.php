<?php

namespace App\Livewire;

use Illuminate\Support\Facades\RateLimiter;
use App\Settings\ChatSettings;
use Livewire\Attributes\Session;
use Livewire\Component;

class StudentDashboardPage extends Component
{
    public $showArchived = false;

    #[Session]
    public $chatPassword;

    public function render(ChatSettings $settings)
    {
        if ($settings->chat_active && $this->chatPassword !== $settings->chat_password) {
            $chatMode = 'password';
        } else if ($settings->chat_active) {
            $chatMode = 'active';
        } else {
            $chatMode = 'inactive';
        }

        return view('livewire.student-dashboard-page', compact('chatMode'));
    }

    public function throttleKey()
    {
        return 'student-chat-password:' . user()->id;
    }

    public function tryChatPassword(ChatSettings $settings)
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), $perMinute = 3)) {
            $seconds = RateLimiter::availableIn($this->throttleKey());
            $this->chatPassword = '';
            $this->dispatch('chat-too-many-attempts', tryAgainInSeconds: $seconds);
            return;
        }

        if ($this->chatPassword !== $settings->chat_password) {
            RateLimiter::hit($this->throttleKey(), 120);
            $this->dispatch('chat-password-incorrect');
        }
    }
}
