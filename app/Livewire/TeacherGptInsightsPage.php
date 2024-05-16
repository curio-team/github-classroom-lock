<?php

namespace App\Livewire;

use App\Http\Controllers\ApiController;
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

    public $models;

    public function render(ChatSettings $settings)
    {
        // crc32 the keys to prevent issues with . in the key, leaves the values as is
        $this->models = collect(ApiController::getModelIds())->mapWithKeys(fn($value, $key) => [crc32($key) => $value]);

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

    public function saveModels(ChatSettings $settings)
    {
        $settings->model_gpt3 = $this->models[crc32('GPT-3.5')];
        $settings->model_gpt4 = $this->models[crc32('GPT-4')];
        $settings->save();
    }
}
