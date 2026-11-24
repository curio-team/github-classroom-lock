<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\Cohort;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class AuditLogPage extends Component
{
    use WithPagination;

    #[Url]
    public ?int $cohort = null;

    #[Url]
    public string $search = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $entries = AuditLog::with('cohort')
            ->when($this->cohort, fn ($query) => $query->where('cohort_id', $this->cohort))
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('action', 'like', '%' . $this->search . '%')
                    ->orWhere('actor_name', 'like', '%' . $this->search . '%')
                    ->orWhere('details', 'like', '%' . $this->search . '%');
            }))
            ->latest('id')
            ->paginate(50);

        return view('livewire.audit-log-page', [
            'entries' => $entries,
            'cohorts' => Cohort::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
