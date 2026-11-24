<x-content.main>
    <x-content.section>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
            <x-headings.section>Examens</x-headings.section>
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" wire:model.live="showArchived" class="rounded">
                Toon afgesloten examens
            </label>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="py-2 pr-4">Examen</th>
                        <th class="py-2 pr-4">Studenten</th>
                        <th class="py-2 pr-4">Groepen</th>
                        <th class="py-2 pr-4">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($exams as $exam)
                        <tr class="border-t">
                            <td class="py-3 pr-4">
                                <a class="font-semibold underline" href="{{ route('exams.show', $exam) }}">{{ $exam->name }}</a>
                                <div class="text-xs text-gray-400">aangemaakt {{ $exam->created_at->format('d-m-Y') }}</div>
                            </td>
                            <td class="py-3 pr-4">{{ $exam->joined_count }} / {{ $exam->students_count }} toegevoegd</td>
                            <td class="py-3 pr-4">{{ $exam->groups_count }}</td>
                            <td class="py-3 pr-4">
                                @php
                                    [$label, $class] = match (true) {
                                        $exam->offboarded_at !== null => ['Afgesloten', 'bg-slate-200 text-slate-600'],
                                        $exam->offboarding_started_at !== null => ['Wordt afgesloten', 'bg-amber-100 text-amber-800'],
                                        $exam->groups_count > 0 && $exam->locked_count === $exam->groups_count => ['🔒 Vergrendeld', 'bg-red-100 text-red-800'],
                                        $exam->locked_count > 0 => ['🔒 Deels vergrendeld', 'bg-amber-100 text-amber-800'],
                                        $exam->students_count > 0 && $exam->joined_count === $exam->students_count => ['🔓 Open', 'bg-green-100 text-green-800'],
                                        default => ['In voorbereiding', 'bg-gray-100 text-gray-700'],
                                    };
                                @endphp
                                <span class="inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $class }}">{{ $label }}</span>
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
        <p class="text-sm text-gray-600">
            Daarna zet je in een paar stappen alles klaar: studenten en groepen invoeren, repositories aanmaken,
            de uitnodigingslink delen, en tijdens het examen de groepen vergrendelen en ontgrendelen.
        </p>

        <form wire:submit="create" class="grid sm:grid-cols-2 gap-4">
            <div class="flex flex-col gap-1">
                <label for="name" class="font-medium">Naam</label>
                <input id="name" type="text" wire:model.live.debounce.300ms="name" placeholder="Examen K1 SD1A november 2026" class="p-2 border border-gray-300 rounded-md">
                @error('name') <p class="text-red-500 italic">{{ $message }}</p> @enderror
            </div>
            <div class="flex flex-col gap-1">
                <label for="slug" class="font-medium">Korte naam</label>
                <input id="slug" type="text" wire:model="slug" placeholder="k1-sd1a-nov26" class="p-2 border border-gray-300 rounded-md">
                <x-content.hint>Wordt het begin van de namen op GitHub, bijv. <code>{{ $slug ?: 'k1-sd1a-nov26' }}-groep-1</code>.</x-content.hint>
                @error('slug') <p class="text-red-500 italic">{{ $message }}</p> @enderror
            </div>

            <details class="sm:col-span-2">
                <summary class="cursor-pointer text-sm text-gray-600">Startbestanden (optioneel)</summary>
                <div class="grid sm:grid-cols-2 gap-4 mt-2">
                    <div class="flex flex-col gap-1">
                        <label for="templateRepository" class="font-medium">Template repository</label>
                        <input id="templateRepository" type="text" wire:model="templateRepository" placeholder="{{ config('github.organization') }}/examen-k1-template" class="p-2 border border-gray-300 rounded-md">
                        <x-content.hint>Elke groep krijgt een kopie. De repository moet op GitHub als template zijn gemarkeerd.</x-content.hint>
                        @error('templateRepository') <p class="text-red-500 italic">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label for="templateProjectNumber" class="font-medium">Template Project-nummer</label>
                        <input id="templateProjectNumber" type="number" min="1" wire:model="templateProjectNumber" placeholder="12" class="p-2 border border-gray-300 rounded-md">
                        <x-content.hint>Elke groep krijgt een kopie. Het nummer staat in de URL: github.com/orgs/{{ config('github.organization') }}/projects/<strong>12</strong>.</x-content.hint>
                        @error('templateProjectNumber') <p class="text-red-500 italic">{{ $message }}</p> @enderror
                    </div>
                </div>
            </details>

            <div class="sm:col-span-2">
                <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">Examen aanmaken</button>
            </div>
        </form>
    </x-content.section>
</x-content.main>
