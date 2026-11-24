<x-content.main
    x-data="{ cheatActive: false }"
    x-on:keydown.window="if (event.altKey && event.code === 'KeyC') { cheatActive = true; }">
    <x-content.section>
        <x-headings.section>GitHub Classroom Teams Vergrendelen</x-headings.section>

        <p>Met de onderstaande tool kun je GitHub Classroom-teams vergrendelen om te voorkomen dat studenten wijzigingen aanbrengen in hun repositories nadat ze de examenruimte hebben verlaten.</p>

        <x-content.hint>
            <p>Het werkt door studenten als leden van hun team te verwijderen, maar houdt hun lidmaatschap bij. Op deze manier kun je later beslissen om ze weer toe te voegen.</p>
            <p>De knop 'Snapshot bijwerken' vindt alle GitHub-teams in de organisatie '{{ config('github.organization') }}'.</p>
        </x-content.hint>
    </x-content.section>

    <x-content.section>
        <x-headings.section>GitHub Projects Vergrendelen</x-headings.section>
        <p>Naast de Teams moet GitHub Projects ook los worden uitgeschakeld aan het eind, en ingeschakeld aan het begin van de werkdag.</p>
        <div class="flex flex-row gap-4">
            @if($isProjectsEnabled)
            <x-buttons.danger wire:click="lockProjects(true)">Vergrendel GitHub Projects</x-buttons.danger>
            @else
            <x-buttons.success wire:click="lockProjects(false)">Ontgrendel GitHub Projects</x-buttons.success>
            @endif
        </div>
    </x-content.section>

    <x-content.section>
        <x-content.stack-layout>
            <x-headings.section>GitHub Teams Snapshot</x-headings.section>
            <x-content.hint>
                <p>Teamleden van vergrendelde teams hebben geen toegang tot hun team of repo. Gebruik de Archiveer knoppen bij teams aan het eind van hun examen.</p>
            </x-content.hint>

            <div class="flex flex-row items-center gap-2">
                <input type="checkbox" wire:model.live="showArchived" class="checkbox" id="showArchived" />
                <label for="showArchived" class="label cursor-pointer">Toon gearchiveerde teams</label>
            </div>

            @forelse ($teams as $team)
                <x-content.stack-layout class="border border-ink/15 bg-surface-subtle p-4 rounded-lg">
                    <div class="flex flex-row justify-between">
                        <h4 class="font-bold text-lg">{{ $team->name }}</h4>
                        <x-content.status-indicator :active="!$team->locked">
                            {{ $team->locked ? 'Locked' : 'Unlocked' }}
                        </x-content.status-indicator>
                    </div>
                    <h5 class="font-bold">Leden:</h5>
                    <x-content.stack-layout row wrap>
                        @forelse ($team->members as $member)
                            <x-content.stack-layout row tight class="bg-white border border-ink/10 py-2 px-4 rounded-lg text-ink">
                                <img src="{{ $member->avatar_url }}"
                                    alt="{{ $member->login }}'s avatar"
                                    class="w-8 h-8 rounded-lg"
                                />
                                {{ $member->login }}
                                <x-buttons.danger
                                    x-show="cheatActive"
                                    x-cloak
                                    class="py-1"
                                    @click="if (confirm('Weet je zeker dat je deze student uit dit team wilt verwijderen in de snapshot? Dit is handig wanneer de student al handmatig is verwijderd uit het team op GitHub.')) { $wire.removeFromTeamSnapshot('{{ $member->id }}') }"
                                    title="Verwijder deze student uit het team in de snapshot"
                                    tight>
                                    &times;
                                </x-buttons.danger>
                            </x-content.stack-layout>
                        @empty
                            <x-content.hint>
                                Geen leden gevonden. Moet de snapshot worden bijgewerkt?
                            </x-content.hint>
                        @endforelse
                    </x-content.stack-layout>
                    <h5 class="font-bold">Project(en):</h5>
                    <x-content.stack-layout row wrap>
                        @isset($teamProjects[$team->id])
                            @forelse ($teamProjects[$team->id] as $project)
                                <a href="{{ $project->url }}" target="_blank">
                                    <x-content.stack-layout row tight class="bg-white border border-ink/10 py-2 px-4 rounded-lg text-ink">
                                        {{ $project->title }}
                                    </x-content.stack-layout>
                                </a>
                            @empty
                                <x-content.hint>
                                    Geen projecten gevonden. Maak ten minste het eerste project aan.
                                </x-content.hint>
                            @endforelse
                        @else
                            <x-content.hint>
                                Geen projecten gevonden. Maak ten minste het eerste project aan.
                            </x-content.hint>
                        @endisset
                        <x-buttons.secondary @click="if (confirm('Weet je zeker dat je een nieuw project wilt aanmaken voor dit team?')) { $wire.requestCreateProject('{{ $team->id }}') }">Cre&euml;er Project</x-buttons.secondary>
                    </x-content.stack-layout>

                    <x-content.stack-layout row x-data="{}">
                        @if ($team->locked)
                            <x-buttons.success wire:click="unlockTeam('{{ $team->id }}')" class="grow">Ontgrendel Team</x-buttons.success>
                            @if ($team->is_archived)
                                <x-buttons.secondary wire:click="unarchiveTeam('{{ $team->id }}')">De-archiveer</x-buttons.secondary>
                            @else
                                <x-buttons.secondary @click="if (confirm('Weet je zeker dat je dit team wilt archiveren?')) { $wire.archiveTeam('{{ $team->id }}') }">Archiveer</x-buttons.secondary>
                            @endif
                        @else
                            <x-buttons.danger wire:click="lockTeam('{{ $team->id }}')" class="grow">Vergrendel Team</x-buttons.danger>
                        @endif
                        <x-buttons.secondary wire:click="refreshTeam('{{ $team->id }}')">Ververs</x-buttons.secondary>
                        {{-- If the team has no members, show a delete button --}}
                        @if (count($team->members) === 0)
                            <x-buttons.danger @click="if (confirm('Weet je zeker dat je dit team wilt verwijderen?')) { $wire.deleteTeam('{{ $team->id }}') }">
                                Verwijder Team
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
                <x-buttons.primary @click="if (confirm('Weet je zeker dat je een nieuw project wilt aanmaken voor elk team zonder projecten? Dit kan tot 30 seconden duren.')) { $wire.createProjectsForTeamsWithoutProject() }">
                    Maak Project voor elk team zonder Projecten
                </x-buttons.primary>

                <x-content.stack-layout row wrap tight x-cloak x-show="cheatActive">
                    @if ($teamsLocked)
                        <x-buttons.success wire:click="unlockAllTeams">Ontgrendel Alle Teams</x-buttons.success>
                    @else
                        <x-buttons.danger wire:click="lockAllTeams">Vergrendel Alle Teams</x-buttons.danger>
                    @endif
                </x-content.stack-layout>
            </x-content.stack-layout>

            <x-content.hint x-cloak x-show="cheatActive">
                Gebruik de &quot;Ontgrendel/Vergrendel Alle Teams&quot; functie niet op dagen dat er op de andere locatie (Roosendaal/Breda) geen examens zijn.
                Open dan individueel de aanwezige teams, zodat studenten die niet aanwezig zijn geen toegang hebben tot hun team.
            </x-content.hint>
        </x-content.stack-layout>
    </x-content.section>

    <div wire:loading>
        <div class="fixed inset-0 z-50 bg-ink/75 grid place-items-center justify-center text-white">
            <div class="flex flex-col items-center gap-2 rounded-lg bg-ink p-6 shadow-card-hover">
                <div class="animate-spin rounded-full h-16 w-16 border-t-2 border-b-2 border-primary"></div>
                Aan het laden...
            </div>
        </div>
    </div>
</x-content.main>
