<x-content.main>
    <x-content.section>
        <x-headings.page>Lock GitHub Classroom Teams</x-headings.page>
        <p>Using this tool you can lock GitHub Classroom teams to prevent students from making changes to their repositories after they've left the examination room.</p>
        <x-content.hint>
            <p>It works by removing students as members of their team, but keeping track of their membership. This way you can later decide to add them again.</p>
            <p>The update snapshot button will find all GitHub teams in the organization '{{ config('app.github_organization') }}' of which the name matches this regex pattern: {{ config('app.github_team_pattern') }}.</p>
        </x-content.hint>
    </x-content.section>

    <x-content.section>
        <x-content.stack-layout>
            <x-headings.section>GitHub Teams Snapshot</x-headings.section>
            <div class="flex flex-row gap-2">
                <input type="checkbox" wire:model="showArchived" class="rounded border-gray-800 border-2 w-6 h-6" id="showArchived" />
                <label for="showArchived">Show Archived</label>
            </div>

            @forelse ($teams as $team)
                <x-content.stack-layout class="border-2 border-gray-800 p-4 rounded">
                    <div class="flex flex-row justify-between">
                        {{-- <x-input.checkbox wire:model="teamIds" :value="$team->id" /> --}}
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
                                No members found. Please update the snapshot.
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
                    No teams found. Please update the snapshot.
                </x-content.hint>
            @endforelse

            <x-content.stack-layout row wrap tight>
                <x-buttons.primary wire:click="makeTeamSnapshot">Update Snapshot</x-buttons.primary>
                @if ($teamsLocked)
                    <x-buttons.primary wire:click="unlockAllTeams">Unlock All Teams</x-buttons.primary>
                @else
                    <x-buttons.danger wire:click="lockAllTeams">Lock All Teams</x-buttons.danger>
                @endif
            </x-content.stack-layout>
        </x-content.stack-layout>
    </x-content.section>

    <div wire:loading>
        <div class="fixed inset-0 bg-gray-800 bg-opacity-75 grid place-items-center justify-center text-white">
            <div class="flex flex-col items-center gap-2 rounded bg-gray-800 p-4 shadow">
                <div class="animate-spin rounded-full h-16 w-16 border-t-2 border-b-2 border-gray-200"></div>
                Loading...
            </div>
        </div>
    </div>
</x-content.main>
