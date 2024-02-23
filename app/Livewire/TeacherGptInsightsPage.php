<?php

namespace App\Livewire;

use Livewire\Attributes\Url;
use App\Models\ChatLog;
use App\Models\User;
use App\Settings\ChatSettings;
use Livewire\Component;

class TeacherGptInsightsPage extends Component
{
    #[Url]
    public $search = '';

    #[Url]
    public $searchUser = '';

    public function render(ChatSettings $settings)
    {
        $users = User::where('name', 'like', '%' . $this->searchUser . '%')
            ->orWhere('email', 'like', '%' . $this->searchUser . '%')
            ->latest()
            ->paginate(10);

        $chatTokensMaxPerUserPerModelPerDay = $settings->max_user_chat_tokens_per_model_per_day;

        $chatLogs = ChatLog::where('prompt', 'like', '%' . $this->search . '%')
            ->latest()
            ->paginate(10);

        return view('livewire.teacher-gpt-insights-page', compact('users', 'chatLogs', 'chatTokensMaxPerUserPerModelPerDay'));
    }

    public function resetTokens(User $user, ChatSettings $settings)
    {
        $user->chats_remaining = $settings->max_user_chat_tokens_per_model_per_day;
        $user->chat_limits_reset = now();
        $user->save();

        $this->dispatch('user-tokens-reset', userName: $user->name);
    }
}
