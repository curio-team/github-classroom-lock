<x-content.main>
    <x-content.section>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <x-headings.section>Examens</x-headings.section>
            <label class="flex items-center gap-2 text-sm text-muted cursor-pointer">
                <input type="checkbox" wire:model.live="showArchived" class="checkbox">
                Toon afgesloten examens
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Examen</th>
                        <th>Studenten</th>
                        <th>Groepen</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($exams as $exam)
                        <tr>
                            <td>
                                <a class="font-bold" href="{{ route('exams.show', $exam) }}">{{ $exam->name }}</a>
                                <div class="text-xs text-muted">aangemaakt {{ $exam->created_at->format('d-m-Y') }}</div>
                            </td>
                            <td>{{ $exam->joined_count }} / {{ $exam->students_count }} toegevoegd</td>
                            <td>{{ $exam->groups_count }}</td>
                            <td>
                                @php
                                    [$label, $class] = match (true) {
                                        $exam->offboarded_at !== null => ['Afgesloten', 'badge-secondary'],
                                        $exam->offboarding_started_at !== null => ['Wordt afgesloten', 'badge-warning'],
                                        $exam->groups_count > 0 && $exam->locked_count === $exam->groups_count => ['🔒 Vergrendeld', 'badge-danger'],
                                        $exam->locked_count > 0 => ['🔒 Deels vergrendeld', 'badge-warning'],
                                        $exam->students_count > 0 && $exam->joined_count === $exam->students_count => ['🔓 Open', 'badge-success'],
                                        default => ['In voorbereiding', 'badge-secondary'],
                                    };
                                @endphp
                                <span class="badge {{ $class }}">{{ $label }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><x-content.empty>Nog geen examens. Maak hieronder het eerste aan.</x-content.empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-content.section>

    <x-content.section>
        <x-headings.section>Nieuw examen</x-headings.section>
        <p class="text-sm text-muted">
            Daarna zet je in een paar stappen alles klaar: studenten en groepen invoeren, repositories aanmaken,
            de uitnodigingslink delen, en tijdens het examen de groepen vergrendelen en ontgrendelen.
        </p>

        <form wire:submit="create" class="grid sm:grid-cols-2 gap-4">
            <div class="flex flex-col gap-1">
                <label for="name" class="label">Naam</label>
                <input id="name" type="text" wire:model.live.debounce.300ms="name" placeholder="Examen K1 SD1A november 2026" class="input">
                @error('name') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-1">
                <label for="slug" class="label">Korte naam</label>
                <input id="slug" type="text" wire:model="slug" placeholder="k1-sd1a-nov26" class="input">
                <x-content.hint>Wordt het begin van de namen op GitHub, bijv. <code>{{ $slug ?: 'k1-sd1a-nov26' }}-groep-1</code>.</x-content.hint>
                @error('slug') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
            </div>

            <details class="sm:col-span-2">
                <summary>Startbestanden (optioneel)</summary>
                <div class="grid sm:grid-cols-2 gap-4 mt-2">
                    <div class="flex flex-col gap-1">
                        <label for="templateRepository" class="label">Template repository</label>
                        <input id="templateRepository" type="text" wire:model="templateRepository" placeholder="{{ config('github.organization') }}/examen-k1-template" class="input">
                        <x-content.hint>Elke groep krijgt een kopie. De repository moet op GitHub als template zijn gemarkeerd.</x-content.hint>
                        @error('templateRepository') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="templateProjectNumber" class="label">Template Project-nummer</label>
                        <input id="templateProjectNumber" type="number" min="1" wire:model="templateProjectNumber" placeholder="12" class="input">
                        <x-content.hint>Elke groep krijgt een kopie. Het nummer staat in de URL: github.com/orgs/{{ config('github.organization') }}/projects/<strong>12</strong>.</x-content.hint>
                        @error('templateProjectNumber') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
                    </div>
                </div>
            </details>

            <div class="sm:col-span-2">
                <button type="submit" class="button">Examen aanmaken</button>
            </div>
        </form>
    </x-content.section>
</x-content.main>
