<x-content.main>
    <x-content.section>
        <x-headings.section>Auditlog</x-headings.section>
        <p>Alle wijzigingen die deze applicatie op GitHub doet, en alle acties van examinatoren.</p>

        <div class="flex flex-col sm:flex-row gap-2">
            <select wire:model.live="cohort" class="p-2 border border-gray-300 rounded-md">
                <option value="">Alle examens</option>
                @foreach ($cohorts as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Zoek op actie, persoon of details…" class="grow p-2 border border-gray-300 rounded-md">
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-gray-500">
                    <tr>
                        <th class="py-2 pr-4">Tijd</th>
                        <th class="py-2 pr-4">Door</th>
                        <th class="py-2 pr-4">Actie</th>
                        <th class="py-2 pr-4">Examen</th>
                        <th class="py-2 pr-4">Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr class="border-t align-top">
                            <td class="py-2 pr-4 whitespace-nowrap text-gray-500">{{ $entry->created_at->format('d-m-Y H:i:s') }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">
                                <span class="text-xs text-gray-400">{{ $entry->actor_type }}</span>
                                {{ $entry->actor_name ?? $entry->actor_id }}
                            </td>
                            <td class="py-2 pr-4"><code>{{ $entry->action }}</code></td>
                            <td class="py-2 pr-4">{{ $entry->cohort?->name }}</td>
                            <td class="py-2 pr-4 text-xs text-gray-600 break-all">
                                @if ($entry->subject_type)
                                    <span class="text-gray-400">{{ class_basename($entry->subject_type) }}#{{ $entry->subject_id }}</span>
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
