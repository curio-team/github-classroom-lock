<x-content.main>
    <x-content.section>
        <x-headings.page>CurioGPT Chat</x-headings.page>
        <p>
            We bieden studenten een ChatGPT-implementatie met behulp van de OpenAI API. Dit is de
            <strong>enige toegestane manier om een Large Language Model (LLM) te gebruiken.</strong>
        </p>

        <p>CurioGPT wordt geleverd aan studenten zonder enige garanties. Het is mogelijk dat het niet altijd beschikbaar is. Als het niet beschikbaar is, mogen de studenten <strong>geen</strong> andere LLM gebruiken.</p>

        <form class="flex flex-col gap-2 p-4 border rounded">
            <strong>(Optioneel) Wachtwoord voor CurioGPT:</strong>
            <div class="flex flex-row gap-2">
                <x-inputs.text class="flex-1" wire:model="chatPassword" name="chatPassword" label="" placeholder="Wachtwoord..." />
                <x-buttons.primary wire:click="updateChatPassword">Sla Wachtwoord Op</x-buttons.primary>
            </div>
            <x-content.hint>Door het wachtwoord alleen in het examenlokaal te delen, voorkomen we dat niet- examen studenten CurioGPT gebruiken.</x-content.hint>
        </form>

        @if ($isChatActive)
            <x-buttons.danger wire:click="lockChat">Vergrendel Chat (zodat niemand erbij kan)</x-buttons.danger>
        @else
            <x-buttons.primary wire:click="unlockChat">Maak Chat Beschikbaar</x-buttons.primary>
        @endif

        <x-content.hint>
            <p>CurioGPT mag alleen worden gebruikt tijdens examenuren.</p>
        </x-content.hint>
    </x-content.section>

    <x-content.section>
        <x-headings.page>GitHub Classroom Teams Vergrendelen</x-headings.page>

        <p>Met de onderstaande tool kun je GitHub Classroom-teams vergrendelen om te voorkomen dat studenten wijzigingen aanbrengen in hun repositories nadat ze de examenruimte hebben verlaten.</p>

        <x-content.hint>
            <p>Het werkt door studenten als leden van hun team te verwijderen, maar houdt hun lidmaatschap bij. Op deze manier kun je later beslissen om ze weer toe te voegen.</p>
            <p>De knop 'Snapshot bijwerken' vindt alle GitHub-teams in de organisatie '{{ config('app.github_organization') }}' waarvan de naam overeenkomt met dit regex-patroon: {{ config('app.github_team_pattern') }}.</p>
        </x-content.hint>
    </x-content.section>

    <x-content.section>
        <x-content.stack-layout>
            <x-headings.section>GitHub Teams Snapshot</x-headings.section>
            <div class="flex flex-row gap-2">
                <input type="checkbox" wire:model.live="showArchived" class="rounded border-gray-800 border-2 w-6 h-6" id="showArchived" />
                <label for="showArchived">Toon gearchiveerde teams</label>
            </div>

            @forelse ($teams as $team)
                <x-content.stack-layout class="border-2 border-gray-800 p-4 rounded">
                    <div class="flex flex-row justify-between">
                        {{-- <x-input.checkbox wire:model.live="teamIds" :value="$team->id" /> --}}
                        <h4>{{ $team->name }}</h4>
                        <x-content.status-indicator :active="!$team->locked">
                            {{ $team->locked ? 'Locked' : 'Unlocked' }}
                        </x-content.status-indicator>
                    </div>
                    <x-content.stack-layout row wrap>
                        @forelse ($team->members as $member)
                            <x-content.stack-layout row tight class="bg-gray-100 py-2 px-4 rounded">
                                <img src="{{ $member->avatar_url }}"
                                    alt="{{ $member->login }}'s avatar"
                                    class="w-8 h-8 rounded"
                                />
                                {{ $member->login }}
                            </x-content.stack-layout>
                        @empty
                            <x-content.hint>
                                Geen leden gevonden. Moet de snapshot worden bijgewerkt?
                            </x-content.hint>
                        @endforelse
                    </x-content.stack-layout>
                    <x-content.stack-layout row x-data="{}">
                        @if ($team->locked)
                            <x-buttons.primary wire:click="unlockTeam('{{ $team->id }}')" class="grow">Unlock Team</x-buttons.primary>
                            @if ($team->is_archived)
                                <x-buttons.secondary wire:click="unarchiveTeam('{{ $team->id }}')">Unarchive</x-buttons.secondary>
                            @else
                                <x-buttons.secondary wire:click="archiveTeam('{{ $team->id }}')">Archive</x-buttons.secondary>
                            @endif
                        @else
                            <x-buttons.danger wire:click="lockTeam('{{ $team->id }}')" class="grow">Lock Team</x-buttons.danger>
                        @endif
                        <x-buttons.secondary wire:click="refreshTeam('{{ $team->id }}')">Refresh State</x-buttons.secondary>
                        {{-- If the team has no members, show a delete button --}}
                        @if (count($team->members) === 0)
                            <x-buttons.danger @click="if (confirm('Are you sure you want to delete this team?')) { $wire.deleteTeam('{{ $team->id }}') }">
                                Delete Team
                            </x-buttons.danger>
                        @endif
                    </x-content.stack-layout>
                </x-content.stack-layout>
            @empty
                <x-content.hint>
                    Geen teams gevonden. Moet de snapshot worden bijgewerkt?
                </x-content.hint>
            @endforelse

            <x-content.stack-layout row wrap tight class="justify-between">
                <x-buttons.primary wire:click="makeTeamSnapshot">Ontdek nieuwe teams op GitHub (Update Snapshot)</x-buttons.primary>
                @if ($teamsLocked)
                    <x-buttons.primary wire:click="unlockAllTeams">Ontgrendel Alle Teams</x-buttons.primary>
                @else
                    <x-buttons.danger wire:click="lockAllTeams">Vergrendel Alle Teams</x-buttons.danger>
                @endif
            </x-content.stack-layout>
        </x-content.stack-layout>
    </x-content.section>

    <div wire:loading>
        <div class="fixed inset-0 bg-gray-800 bg-opacity-75 grid place-items-center justify-center text-white">
            <div class="flex flex-col items-center gap-2 rounded bg-gray-800 p-4 shadow">
                <div class="animate-spin rounded-full h-16 w-16 border-t-2 border-b-2 border-gray-200"></div>
                Aan het laden...
            </div>
        </div>
    </div>
</x-content.main>
