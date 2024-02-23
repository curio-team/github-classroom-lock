<?php

namespace App\Livewire;

use App\Settings\ChatSettings;
use Livewire\Component;

class StudentDashboardPage extends Component
{
    public $showArchived = false;

    public function render(ChatSettings $settings)
    {
        $isChatActive = $settings->chat_active;

        return view('livewire.student-dashboard-page', compact('isChatActive'));
    }
}
