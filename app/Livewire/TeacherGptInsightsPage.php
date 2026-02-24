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

    public $models = [];

    public function mount(ChatSettings $settings)
    {
        $this->models = $settings->models;
    }

    public function render(ChatSettings $settings)
    {
        $users = User::where('name', 'like', '%' . $this->searchUser . '%')
            ->orWhere('email', 'like', '%' . $this->searchUser . '%')
            ->latest()
            ->paginate(
                perPage: 10,
                pageName: 'usersPage'
            );

        $chatTokensMaxPerUserPerModelPerDay = $settings->getAllMaxUserChatTokensPerModelPerDay();

        $chatLogs = ChatLog::where('prompt', 'like', '%' . $this->search . '%')
            ->latest()
            ->paginate(
                perPage: 25,
                pageName: 'logsPage'
            );

        return view('livewire.teacher-gpt-insights-page', compact('users', 'chatLogs', 'chatTokensMaxPerUserPerModelPerDay'));
    }

    public function resetTokens(User $user, ChatSettings $settings)
    {
        $user->chats_remaining = $settings->getAllMaxUserChatTokensPerModelPerDay();
        $user->chat_limits_reset = now();
        $user->save();

        $this->dispatch('user-tokens-reset', userName: $user->name);
    }

    public function halveAllTokens()
    {
        User::query()->update([
            // We only halve the advanced tokens, as the mini tokens have no limit (-1)
            'chats_remaining' => User::raw('JSON_SET(chats_remaining, "$.advanced", chats_remaining->"$.advanced" / 2)'),
        ]);

        $this->dispatch('all-tokens-halved');
    }

    public function addModel()
    {
        $this->models[] = ['name' => '', 'model_id' => '', 'token_limit' => -1];
    }

    public function removeModel(int $index)
    {
        array_splice($this->models, $index, 1);
    }

    public function saveModels(ChatSettings $settings)
    {
        $this->validate([
            'models.*.name'        => 'required|string|max:64',
            'models.*.model_id'    => 'required|string|max:128',
            'models.*.token_limit' => 'required|integer|min:-1',
        ]);

        $settings->models = array_values($this->models);
        $settings->save();

        $this->dispatch('models-saved');
    }
}
