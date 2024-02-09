<?php

namespace App\Http\Livewire;

use App\Settings\GeneralSettings;
use Livewire\Component;

class StudentDashboardPage extends Component
{
    public $showArchived = false;

    public function render(GeneralSettings $settings)
    {
        $isChatActive = $settings->chat_active;

        return view('livewire.student-dashboard-page', compact('isChatActive'));
    }
}
