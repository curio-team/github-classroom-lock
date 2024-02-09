<?php

namespace App\Http\Livewire;

use Livewire\Component;

class ChatUsageStats extends Component
{
    public function render()
    {
        $chatLimits = user()->getChatLimits();

        return view('livewire.chat-usage-stats', compact('chatLimits'));
    }
}
