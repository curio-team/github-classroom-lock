<?php

namespace App\Livewire;

use App\Models\Cohort;
use App\Services\Audit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Overview of all exams, and creating a new one.
 *
 * (In the code an exam is a Cohort: one roster, one invite link.)
 */
class ExamIndexPage extends Component
{
    public string $name = '';

    public string $slug = '';

    public string $templateRepository = '';

    public ?int $templateProjectNumber = null;

    public bool $showArchived = false;

    public function updatedName(string $value): void
    {
        $this->slug = Str::slug($value);
    }

    public function create()
    {
        $this->slug = Str::slug($this->slug ?: $this->name);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('cohorts', 'slug')],
            'templateRepository' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
            'templateProjectNumber' => ['nullable', 'integer', 'min:1'],
        ], [
            'name.required' => 'Geef het examen een naam.',
            'slug.unique' => 'Deze korte naam is al in gebruik.',
            'slug.regex' => 'Alleen kleine letters, cijfers en streepjes.',
            'templateRepository.regex' => 'Gebruik het formaat eigenaar/repository.',
        ]);

        $cohort = Cohort::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'organization' => config('github.organization'),
            'template_repository' => $data['templateRepository'] ?: null,
            'template_project_number' => $data['templateProjectNumber'] ?: null,
            'created_by' => (string) auth()->id(),
        ]);

        Audit::record('cohort.created', $cohort, ['name' => $cohort->name, 'slug' => $cohort->slug]);

        return redirect()->route('exams.show', ['cohort' => $cohort, 'step' => 'students']);
    }

    public function render()
    {
        $exams = Cohort::withCount([
            'students',
            'groups',
            'students as joined_count' => fn ($query) => $query->where('status', 'joined'),
            'groups as locked_count' => fn ($query) => $query->whereNotNull('locked_at'),
        ])
            ->when(! $this->showArchived, fn ($query) => $query->whereNull('offboarded_at'))
            ->latest()
            ->get();

        return view('livewire.exam-index-page', compact('exams'));
    }
}
