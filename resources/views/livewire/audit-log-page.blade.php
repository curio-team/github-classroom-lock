<x-content.main>
    <x-content.section>
        <x-headings.section>Auditlog</x-headings.section>
        <p>Alle wijzigingen die deze applicatie op GitHub doet, en alle acties van examinatoren.</p>

        <div class="flex flex-col sm:flex-row gap-2">
            <select wire:model.live="cohort" class="input sm:w-64">
                <option value="">Alle examens</option>
                @foreach ($cohorts as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Zoek op actie, persoon of details…" class="input grow">
        </div>

        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tijd</th>
                        <th>Door</th>
                        <th>Actie</th>
                        <th>Examen</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr class="align-top">
                            <td class="whitespace-nowrap text-muted">{{ $entry->created_at->format('d-m-Y H:i:s') }}</td>
                            <td class="whitespace-nowrap">
                                <span class="text-xs text-muted">{{ $entry->actor_type }}</span>
                                {{ $entry->actor_name ?? $entry->actor_id }}
                            </td>
                            <td><code>{{ $entry->action }}</code></td>
                            <td>{{ $entry->cohort?->name }}</td>
                            <td class="text-xs text-muted break-all">
                                @if ($entry->subject_type)
                                    <span class="text-ink/40">{{ class_basename($entry->subject_type) }}#{{ $entry->subject_id }}</span>
                                @endif
                                {{ $entry->details ? json_encode($entry->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-content.empty>Nog niets gelogd.</x-content.empty></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $entries->links() }}
    </x-content.section>
</x-content.main>
