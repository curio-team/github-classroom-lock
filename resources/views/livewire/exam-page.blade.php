<div @if ($isBusy) wire:poll.3s @endif>
<x-content.main>
    {{-- Header + steps --}}
    <x-content.section>
        <a href="{{ route('exams.index') }}" class="self-start text-sm font-bold">← Examens</a>
        <h2 class="text-2xl font-bold">{{ $cohort->name }}</h2>

        <nav class="grid grid-cols-2 sm:grid-cols-5 gap-2 mt-2">
            @foreach ($steps as $key => $label)
                <button type="button" wire:click="$set('step', '{{ $key }}')"
                    @class([
                        'text-left rounded-lg border px-3 py-2 text-sm cursor-pointer transition-colors duration-300',
                        'border-primary bg-primary text-white' => $step === $key,
                        'border-ink/15 bg-white text-ink hover:border-primary' => $step !== $key,
                    ])>
                    <span class="block text-xs opacity-70">Stap {{ $loop->iteration }} @if ($progress[$key]) ✓ @endif</span>
                    <span class="font-bold">{{ $label }}</span>
                    <span class="block text-xs opacity-70">
                        @switch($key)
                            @case('students') {{ $students->count() }} studenten, {{ $groups->count() }} groepen @break
                            @case('prepare') {{ $provisionedCount }} / {{ $activeGroupCount }} groepen klaar @break
                            @case('invite') {{ $statusCounts['joined'] }} / {{ $students->count() }} toegevoegd @break
                            @case('exam') {{ $lockedCount === 0 ? 'Open' : ($lockedCount === $activeGroupCount ? 'Vergrendeld' : "{$lockedCount} vergrendeld") }} @break
                            @case('finish') {{ $cohort->offboarded_at ? 'Afgesloten' : ($cohort->offboarding_started_at ? 'Bezig…' : 'Na het examen') }} @break
                        @endswitch
                    </span>
                </button>
            @endforeach
        </nav>
    </x-content.section>

    @if ($cohort->offboarding_started_at && ! $cohort->offboarded_at)
        <div class="alert alert-warning">
            Het examen wordt afgesloten (gestart {{ $cohort->offboarding_started_at->format('d-m-Y H:i') }}). Deze pagina wordt vanzelf bijgewerkt.
        </div>
    @elseif ($cohort->offboarded_at)
        <div class="alert alert-secondary">
            Dit examen is afgesloten op {{ $cohort->offboarded_at->format('d-m-Y H:i') }}.
        </div>
    @endif

    @if ($orgProblems)
        <div class="alert alert-danger flex flex-col gap-2">
            <strong>Controleer de GitHub-organisatie</strong>
            @foreach ($orgProblems as $check)
                <div>
                    <span class="font-bold">{{ $check['label'] }}:</span> {{ $check['message'] }}
                    @if ($check['key'] === 'base_permission' && $check['level'] === 'error')
                        <button type="button" class="ml-2 font-bold underline cursor-pointer" x-on:click="if (confirm('Basisrechten van de hele organisatie op No permission zetten?')) $wire.fixBasePermission()">Nu oplossen</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    <datalist id="group-names">
        @foreach ($groups->whereNull('archived_at') as $group)
            <option value="{{ $group->name }}"></option>
        @endforeach
    </datalist>

    {{-- 1. Students --}}
    @if ($step === 'students')
        <x-content.section>
            <x-headings.section>Welke studenten doen mee, en in welke groep?</x-headings.section>
            <p>
                Upload een CSV-bestand met de kolommen <code>github_username</code> en <code>group</code>
                (een export uit Excel met puntkomma's werkt ook), of plak de lijst hieronder.
            </p>
            <pre class="bg-surface-subtle border border-ink/10 rounded-lg p-3 text-xs">github_username,group
octocat,Groep 1
monalisa,Groep 1
hubot,Groep 2</pre>

            <form wire:submit="previewRoster" class="flex flex-col gap-2">
                <input type="file" wire:model="rosterFile" accept=".csv,.txt,.tsv,text/csv" class="text-sm">
                @error('rosterFile') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
                <details>
                    <summary>…of plak de lijst</summary>
                    <textarea wire:model="rosterText" rows="8" class="input w-full mt-2 font-mono text-xs" placeholder="github_username,group"></textarea>
                </details>
                <div>
                    <button type="submit" class="button self-start" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="previewRoster">Controleren</span>
                        <span wire:loading wire:target="previewRoster">Gebruikers opzoeken op GitHub…</span>
                    </button>
                </div>
            </form>
            <x-content.hint>
                Een fout gemaakt? Upload gewoon een verbeterde lijst: studenten worden herkend aan hun GitHub-account, er ontstaan geen dubbelen.
            </x-content.hint>
        </x-content.section>

        @if ($preview)
            <x-content.section>
                <x-headings.section>Controle</x-headings.section>

                <div class="flex flex-wrap gap-2 text-sm">
                    <span class="badge badge-secondary">{{ count($preview->rows) }} geldige studenten</span>
                    <span class="badge badge-success">{{ count($preview->changes['new']) }} nieuw</span>
                    <span class="badge badge-warning">{{ count($preview->changes['moved']) }} andere groep</span>
                    <span class="badge badge-danger">{{ count($preview->changes['removed']) }} verwijderd</span>
                    <span class="badge badge-secondary">{{ count($preview->groupCounts) }} groepen</span>
                </div>

                @if ($preview->errors)
                    <div class="alert alert-danger">
                        <strong>{{ count($preview->errors) }} fout(en): pas het bestand aan en controleer opnieuw.</strong>
                        <ul class="list-disc pl-5 text-sm">
                            @foreach ($preview->errors as $error)
                                <li>@if ($error['line']) Regel {{ $error['line'] }}: @endif {{ $error['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($preview->warnings)
                    <div class="alert alert-warning">
                        <strong>Let op</strong>
                        <ul class="list-disc pl-5 text-sm">
                            @foreach ($preview->warnings as $warning)
                                <li>@if ($warning['line']) Regel {{ $warning['line'] }}: @endif {{ $warning['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <strong>Groepen</strong>
                        <ul>
                            @foreach ($preview->groupCounts as $name => $count)
                                <li>{{ $name }}: {{ $count }} @if (in_array($name, $preview->newGroups)) <span class="text-success-ink font-bold">(nieuw)</span> @endif</li>
                            @endforeach
                        </ul>
                    </div>
                    <div>
                        @if ($preview->changes['moved'])
                            <strong>Naar een andere groep</strong>
                            <ul>
                                @foreach ($preview->changes['moved'] as $row)
                                    <li>{{ $row['github_login'] }}: {{ $row['from'] ?? '—' }} → {{ $row['group'] }}</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($preview->changes['removed'])
                            <strong>Niet meer op de lijst</strong>
                            <ul>
                                @foreach ($preview->changes['removed'] as $row)
                                    <li>{{ $row['github_login'] }} ({{ $row['group'] }})</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="flex gap-2">
                    @unless ($preview->hasErrors())
                        <x-buttons.primary wire:click="applyRoster">Opslaan</x-buttons.primary>
                    @endunless
                    <x-buttons.secondary wire:click="cancelPreview">Annuleren</x-buttons.secondary>
                </div>
            </x-content.section>
        @endif

        @if (! $preview && ! $cohort->offboarding_started_at)
            <x-content.section>
                <x-headings.section>Eén student toevoegen</x-headings.section>
                <p class="text-sm text-muted">
                    Zonder een nieuwe lijst te uploaden, bijvoorbeeld als een student met een ander GitHub-account meedoet.
                    Typ een nieuwe groepsnaam om een groep aan te maken.
                </p>
                @include('livewire.partials.add-student', ['context' => 'students'])
            </x-content.section>
        @endif

        @if ($groups->isNotEmpty() && ! $preview)
            <x-content.section>
                <div class="flex justify-between items-center">
                    <x-headings.section>Huidige groepen</x-headings.section>
                    <x-buttons.primary wire:click="$set('step', 'prepare')">Volgende: klaarzetten →</x-buttons.primary>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
                    @foreach ($groups as $group)
                        <div wire:key="roster-group-{{ $group->id }}" class="rounded-lg border border-ink/10 bg-surface-subtle p-3 flex flex-col gap-1">
                            <strong>{{ $group->name }}</strong>
                            @forelse ($group->students as $student)
                                <div wire:key="roster-student-{{ $student->id }}" class="flex justify-between items-start gap-2">
                                    <span class="pt-0.5 break-all">{{ $student->github_login }}</span>
                                    @unless ($cohort->offboarding_started_at)
                                        @include('livewire.partials.student-actions', ['student' => $student])
                                    @endunless
                                </div>
                            @empty
                                <div class="text-muted">geen studenten</div>
                            @endforelse
                        </div>
                    @endforeach
                </div>
                @unless ($cohort->offboarding_started_at)
                    <x-content.hint>
                        Een student naar een andere groep verplaatsen of verwijderen gaat meteen, ook op GitHub als de student al is toegevoegd.
                    </x-content.hint>
                @endunless
            </x-content.section>
        @endif
    @endif

    {{-- 2. Prepare --}}
    @if ($step === 'prepare' && $organizationProjects === false)
        <div class="alert alert-danger flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <strong>Projects staan uit voor de organisatie.</strong>
                Zonder Projects kan een groep geen Project krijgen. Deze instelling geldt voor de hele organisatie; misschien heeft iemand hem uitgezet om een examen te vergrendelen.
            </div>
            <x-buttons.primary class="shrink-0" x-on:click="if (confirm('Projects aanzetten voor de hele organisatie?')) $wire.setOrganizationProjects(true)">Projects aanzetten</x-buttons.primary>
        </div>
    @endif

    @if ($step === 'prepare')
        <x-content.section>
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                <x-headings.section>Repositories klaarzetten op GitHub</x-headings.section>
                @if ($groups->isNotEmpty() && ! $cohort->offboarding_started_at)
                    <x-buttons.primary wire:click="provisionAll" wire:loading.attr="aria-disabled">
                        {{ $provisionedCount === 0 ? 'Alles klaarzetten' : 'Alles controleren en herstellen' }}
                    </x-buttons.primary>
                @endif
            </div>
            <p class="text-sm text-muted">
                Elke groep krijgt een eigen team, een private repository en een Project, die alleen de groep zelf kan zien.
                Je kunt dit veilig opnieuw doen: wat al bestaat wordt hergebruikt.
                Groepen staan vergrendeld tot je ze ontgrendelt bij stap 4, dus je kunt alles ruim van tevoren klaarzetten en studenten al laten inloggen.
                Komt er een groep bij terwijl het examen open is, dan staat die meteen open.
            </p>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Groep</th>
                            <th>Status</th>
                            <th>Op GitHub</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groups as $group)
                            <tr class="align-top">
                                <td>
                                    <strong>{{ $group->name }}</strong>
                                    <div class="text-xs text-muted">{{ $group->students_count }} studenten</div>
                                </td>
                                <td>
                                    <span class="badge {{ $group->status->color() }}">{{ $group->status->label() }}</span>
                                    @if (in_array($group->status, [\App\Enums\GroupStatus::Pending, \App\Enums\GroupStatus::Provisioning, \App\Enums\GroupStatus::Failed], true))
                                        <div class="flex flex-wrap gap-x-2 mt-1">
                                            @foreach (\App\Enums\ProvisioningStep::cases() as $provisioningStep)
                                                <span @class([
                                                    'text-xs',
                                                    'text-success-ink' => $group->hasCompleted($provisioningStep),
                                                    'text-danger-ink font-bold' => $group->failed_step === $provisioningStep->value,
                                                    'text-ink/30' => ! $group->hasCompleted($provisioningStep) && $group->failed_step !== $provisioningStep->value,
                                                ])>{{ $group->hasCompleted($provisioningStep) ? '✓' : ($group->failed_step === $provisioningStep->value ? '✗' : '○') }} {{ $provisioningStep->label() }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($group->last_error)
                                        <div class="text-xs text-danger-ink mt-1 break-all">{{ $group->last_error }}</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-x-3">
                                        @if ($group->repository_url && $group->hasRepositoryOnGitHub())
                                            <a class="font-bold" target="_blank" href="{{ $group->repository_url }}">Repository</a>
                                        @endif
                                        @if ($group->project_url && $group->hasProjectOnGitHub())
                                            <a class="font-bold" target="_blank" href="{{ $group->project_url }}">Project</a>
                                        @endif
                                        @if ($group->hasTeamOnGitHub())
                                            <a class="font-bold" target="_blank" href="https://github.com/orgs/{{ $cohort->organization }}/teams/{{ $group->team_slug }}">Team</a>
                                        @endif
                                    </div>
                                </td>
                                <td class="text-right">
                                    @if (! $cohort->offboarding_started_at && $group->status === \App\Enums\GroupStatus::Failed)
                                        <x-buttons.secondary tight wire:click="provisionGroup({{ $group->id }})">Opnieuw proberen</x-buttons.secondary>
                                    @elseif (! $cohort->offboarding_started_at && $group->status === \App\Enums\GroupStatus::Pending && $provisionedCount > 0)
                                        <x-buttons.secondary tight wire:click="provisionGroup({{ $group->id }})">Klaarzetten</x-buttons.secondary>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-content.empty>Nog geen groepen. Voer eerst de studenten in (stap 1).</x-content.empty></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($activeGroupCount > 0 && $provisionedCount === $activeGroupCount)
                <div class="flex justify-end">
                    <x-buttons.primary wire:click="$set('step', 'invite')">Volgende: uitnodigen →</x-buttons.primary>
                </div>
            @endif
        </x-content.section>
    @endif

    {{-- 3. Invite --}}
    @if ($step === 'invite')
        <x-content.section>
            <x-headings.section>Studenten uitnodigen</x-headings.section>
            <p class="text-sm text-muted">
                Deel deze link met de studenten (bijv. op het bord of in Teams). Ze loggen in met GitHub en komen automatisch in hun eigen groep.
                Alleen studenten van de lijst kunnen meedoen: een doorgestuurde link geeft anderen geen toegang.
            </p>

            <div class="flex items-center gap-2">
                @include('livewire.partials.invite-status', ['status' => $cohort->inviteLinkStatus()])
                @if ($cohort->invite_expires_at && $cohort->inviteLinkStatus() !== 'none')
                    <span class="text-sm text-muted">geldig t/m {{ $cohort->invite_expires_at->format('d-m-Y') }}</span>
                @endif
            </div>

            @if ($cohort->inviteLinkIsUsable())
                <div class="flex gap-2" x-data="{
                    copied: false,
                    async copy() {
                        const link = this.$refs.link;
                        try {
                            await navigator.clipboard.writeText(link.value);
                        } catch (e) {
                            // No Clipboard API (e.g. plain http on a non-localhost address): copy the selection.
                            link.select();
                            document.execCommand('copy');
                        }
                        this.copied = true;
                        setTimeout(() => this.copied = false, 2000);
                    },
                }">
                    <input type="text" readonly x-ref="link" value="{{ $cohort->inviteUrl() }}" class="input grow text-sm" x-on:click="$el.select()">
                    <button type="button" x-on:click="copy()" class="button">
                        <span x-text="copied ? 'Gekopieerd!' : 'Kopiëren'">Kopiëren</span>
                    </button>
                </div>
            @endif

            @if (! $cohort->offboarding_started_at)
                <details @if (! $cohort->inviteLinkIsUsable()) open @endif>
                    <summary>{{ $cohort->inviteLinkIsUsable() ? 'Link aanpassen' : 'Link maken' }}</summary>
                    <div class="flex flex-col sm:flex-row sm:items-end gap-2 mt-2">
                        <div class="flex flex-col gap-1">
                            <label for="inviteExpiresAt" class="label">Geldig tot en met</label>
                            <input id="inviteExpiresAt" type="date" wire:model="inviteExpiresAt" class="input">
                            @error('inviteExpiresAt') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
                        </div>
                        @if ($cohort->inviteLinkIsUsable())
                            <x-buttons.secondary wire:click="updateInviteExpiry">Datum opslaan</x-buttons.secondary>
                            <x-buttons.secondary x-on:click="if (confirm('De huidige link werkt dan niet meer. Doorgaan?')) $wire.generateInviteLink()">Nieuwe link</x-buttons.secondary>
                            <x-buttons.danger x-on:click="if (confirm('Link intrekken? Studenten kunnen er dan niet meer mee binnenkomen.')) $wire.revokeInviteLink()">Intrekken</x-buttons.danger>
                        @else
                            <x-buttons.primary wire:click="generateInviteLink">Uitnodigingslink maken</x-buttons.primary>
                        @endif
                    </div>
                </details>
            @endif
        </x-content.section>

        <x-content.section>
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                <x-headings.section>Wie is er binnen? ({{ $statusCounts['joined'] }} / {{ $students->count() }})</x-headings.section>
                @if ($statusCounts['failed'] + $statusCounts['invited'] > 0 && ! $cohort->offboarding_started_at)
                    <x-buttons.secondary wire:click="retryWaitingStudents" wire:loading.attr="aria-disabled">Opnieuw controleren</x-buttons.secondary>
                @endif
            </div>

            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Groep</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($students->sortBy(fn ($s) => [$s->status->value === 'joined' ? 1 : 0, $s->github_login]) as $student)
                            <tr class="align-top" wire:key="student-{{ $student->id }}">
                                <td>
                                    <a class="font-bold" target="_blank" href="https://github.com/{{ $student->github_login }}">{{ $student->github_login }}</a>
                                </td>
                                <td>{{ $student->group?->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $student->status->color() }}">{{ $student->status->label() }}</span>
                                    @if ($student->failure_reason)
                                        <div class="text-xs text-danger-ink mt-1 break-all">{{ $student->failure_reason }}</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @unless ($cohort->offboarding_started_at)
                                        <div class="flex flex-col items-end gap-2">
                                            @if (in_array($student->status, [\App\Enums\StudentStatus::Failed, \App\Enums\StudentStatus::Invited, \App\Enums\StudentStatus::Imported]) && $student->last_attempt_at)
                                                <x-buttons.secondary tight wire:click="retryStudent({{ $student->id }})" wire:loading.attr="aria-disabled">Opnieuw proberen</x-buttons.secondary>
                                            @endif
                                            @include('livewire.partials.student-actions', ['student' => $student])
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-content.empty>Nog geen studenten. Voer ze in bij stap 1.</x-content.empty></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @unless ($cohort->offboarding_started_at)
                <details @if ($errors->has('newStudentLogin') || $errors->has('newStudentGroup')) open @endif>
                    <summary>Student toevoegen</summary>
                    <div class="mt-2 flex flex-col gap-2">
                        <p class="text-sm text-muted">
                            Kan een student niet inloggen op het eigen GitHub-account? Voeg het account toe waarmee de student wel kan inloggen,
                            en verwijder het oude account hierboven via <em>Wijzigen</em>. Typ een nieuwe groepsnaam om een groep aan te maken.
                        </p>
                        @include('livewire.partials.add-student', ['context' => 'invite'])
                    </div>
                </details>
            @endunless

            <x-content.hint>
                Nog niet ingelogd: de student heeft de link nog niet geopend. Uitgenodigd: de student moet de uitnodiging nog accepteren op
                github.com/orgs/{{ $cohort->organization }}/invitation. Toegevoegd: de student zit in de eigen groep.
            </x-content.hint>

            @if ($orgChecks)
                <details>
                    <summary>Controles van de GitHub-organisatie</summary>
                    <ul class="flex flex-col gap-1 mt-2 text-sm">
                        @foreach ($orgChecks as $check)
                            <li>
                                {{ ['ok' => '✓', 'warning' => '⚠', 'error' => '✗', 'info' => 'ℹ'][$check['level']] }}
                                <strong>{{ $check['label'] }}:</strong> {{ $check['message'] }}
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="refreshChecks" class="mt-2 text-sm font-bold text-primary hover:text-primary-dark cursor-pointer">Vernieuwen</button>
                </details>
            @endif
        </x-content.section>
    @endif

    {{-- 4. During the exam --}}
    @if ($step === 'exam')
        <x-content.section>
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3">
                <div>
                    <x-headings.section>Tijdens het examen</x-headings.section>
                    <p class="text-sm text-muted">
                        Groepen staan vergrendeld tot je ze hier ontgrendelt. Ontgrendel bij de start van het examen, vergrendel aan het eind van de dag.
                        Een vergrendelde groep kan niet bij zijn repository en Project; de studenten blijven wel in hun team, zodat toegewezen taken behouden blijven.
                    </p>
                </div>
                @if ($activeGroupCount > 0 && ! $cohort->offboarding_started_at)
                    <div class="flex gap-2 shrink-0">
                        <x-buttons.danger big x-on:click="if (confirm('Alle groepen vergrendelen?')) $wire.lockAll()">🔒 Alles vergrendelen</x-buttons.danger>
                        <x-buttons.success big x-on:click="if (confirm('Alle groepen ontgrendelen?')) $wire.unlockAll()">🔓 Alles ontgrendelen</x-buttons.success>
                    </div>
                @endif
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @forelse ($groups->whereNull('archived_at') as $group)
                    <div wire:key="lock-{{ $group->id }}" @class([
                        'rounded-lg border-2 p-4 flex flex-col gap-2',
                        'border-danger/40 bg-white' => $group->isLocked(),
                        'border-success/40 bg-white' => ! $group->isLocked(),
                    ])>
                        <div class="flex justify-between items-start">
                            <strong>{{ $group->name }}</strong>
                            <span class="text-sm font-bold">
                                @if ($group->lock_pending)
                                    ⏳ {{ $group->isLocked() ? 'Vergrendelen…' : 'Ontgrendelen…' }}
                                @else
                                    {{ $group->isLocked() ? '🔒 Vergrendeld' : '🔓 Open' }}
                                @endif
                            </span>
                        </div>

                        <ul class="text-sm">
                            @foreach ($group->students as $student)
                                <li class="flex justify-between">
                                    <span>{{ $student->github_login }}</span>
                                    <span class="text-xs {{ $student->status->value === 'joined' ? 'text-success-ink' : 'text-warning-ink' }}">{{ $student->status->label() }}</span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($group->lock_error)
                            <div class="text-xs text-danger-ink break-all">{{ $group->lock_error }}</div>
                        @endif

                        @if (! $cohort->offboarding_started_at && ! $group->lock_pending)
                            <div class="mt-auto">
                                @if ($group->isLocked())
                                    <x-buttons.success wire:click="unlockGroup({{ $group->id }})">Ontgrendelen</x-buttons.success>
                                @else
                                    <x-buttons.danger wire:click="lockGroup({{ $group->id }})">Vergrendelen</x-buttons.danger>
                                @endif
                            </div>
                        @endif
                    </div>
                @empty
                    <x-content.empty>Nog geen groepen.</x-content.empty>
                @endforelse
            </div>

            <details class="border-t border-ink/10 pt-3">
                <summary>Projects voor de hele organisatie uitzetten</summary>
                <div class="flex flex-col gap-2 mt-2 text-sm">
                    <p>
                        Als een Project op GitHub een "base role" heeft voor alle leden van de organisatie, kunnen studenten het blijven zien,
                        ook als hun groep vergrendeld is. Controleer dit bij een Project via <em>Settings → Manage access</em>.
                        Is dat het geval, zet dan hier Projects uit voor de <strong>hele organisatie</strong> (dus ook voor andere examens die nu lopen).
                    </p>
                    <div class="flex items-center gap-3">
                        <span>Nu: <strong>{{ $organizationProjects === null ? 'onbekend' : ($organizationProjects ? 'aan' : 'uit') }}</strong></span>
                        @if ($organizationProjects === false)
                            <x-buttons.secondary wire:click="setOrganizationProjects(true)">Projects aanzetten</x-buttons.secondary>
                        @else
                            <x-buttons.secondary x-on:click="if (confirm('Projects uitzetten voor de hele organisatie?')) $wire.setOrganizationProjects(false)">Projects uitzetten</x-buttons.secondary>
                        @endif
                    </div>
                </div>
            </details>
        </x-content.section>
    @endif

    {{-- 5. Finish --}}
    @if ($step === 'finish')
        <x-content.section>
            <x-headings.section>Examen afsluiten</x-headings.section>
            <p>Na het examen: haal de studenten uit hun teams en uit {{ $cohort->organization }}, en archiveer de repositories zodat het werk bewaard blijft (alleen-lezen). Eigenaren van de organisatie worden nooit verwijderd.</p>

            <div class="flex flex-col gap-2">
                <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="offboardOptions.remove_team_memberships" class="checkbox mt-0.5"> <span>Studenten uit hun groepsteam halen</span></label>
                <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="offboardOptions.remove_org_membership" class="checkbox mt-0.5"> <span>Studenten uit {{ $cohort->organization }} halen (openstaande uitnodigingen worden ingetrokken)</span></label>
                <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="offboardOptions.archive_repositories" class="checkbox mt-0.5"> <span>Repositories archiveren</span></label>
            </div>

            <div>
                <x-buttons.secondary wire:click="previewOffboarding">Bekijk wat er gaat gebeuren</x-buttons.secondary>
            </div>
        </x-content.section>

        @if ($offboardPlan)
            <x-content.section>
                <x-headings.section>Dit gaat er gebeuren</x-headings.section>

                @if ($offboardPlan['warnings'])
                    <div class="alert alert-warning">
                        <ul class="list-disc pl-5 text-sm">
                            @foreach ($offboardPlan['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! $offboardPlan['students'] && ! $offboardPlan['groups'])
                    <x-content.empty>Er is niets meer te doen.</x-content.empty>
                @else
                    <div class="grid sm:grid-cols-2 gap-4 text-sm">
                        <div>
                            <strong>Studenten ({{ count($offboardPlan['students']) }})</strong>
                            <ul class="flex flex-col gap-1">
                                @foreach ($offboardPlan['students'] as $entry)
                                    <li>
                                        <strong>{{ $entry['login'] }}</strong>
                                        <ul class="list-disc pl-5 text-muted">
                                            @foreach ($entry['actions'] as $action)
                                                <li>{{ $action['label'] }}</li>
                                            @endforeach
                                        </ul>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                        <div>
                            <strong>Repositories ({{ count($offboardPlan['groups']) }})</strong>
                            <ul class="list-disc pl-5 text-muted">
                                @foreach ($offboardPlan['groups'] as $entry)
                                    @foreach ($entry['actions'] as $action)
                                        <li>{{ $action['label'] }}</li>
                                    @endforeach
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 border-t border-ink/10 pt-4">
                        <label for="offboardConfirmation" class="label">Typ <code>{{ $cohort->name }}</code> om te bevestigen</label>
                        <input id="offboardConfirmation" type="text" wire:model="offboardConfirmation" class="input" autocomplete="off">
                        @error('offboardConfirmation') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
                        <div>
                            <x-buttons.danger wire:click="confirmOffboarding">Examen afsluiten</x-buttons.danger>
                        </div>
                        <x-content.hint>De uitnodigingslink wordt ingetrokken. Lukt iets niet, dan kun je dit veilig opnieuw uitvoeren.</x-content.hint>
                    </div>
                @endif
            </x-content.section>
        @endif

        <x-content.section>
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-2">
                <x-headings.section>Backup van alle repositories</x-headings.section>
                @if ($remainingOnGitHub['repositories'] > 0 && $cohort->backup_status !== 'pending')
                    <x-buttons.secondary wire:click="createBackup" wire:loading.attr="aria-disabled">
                        {{ $cohort->backupFile() ? 'Nieuwe backup maken' : 'Backup maken' }}
                    </x-buttons.secondary>
                @endif
            </div>
            <p class="text-sm text-muted">
                Eén zip-bestand met een volledige kopie (alle bestanden, commits en branches) van elke repository, en een overzicht van de groepen en hun studenten.
                Issues, pull requests en Projects zitten er niet in.
            </p>

            @if ($cohort->backup_status === 'pending')
                <div class="alert alert-warning">De backup wordt gemaakt. Dit kan enkele minuten duren; deze pagina wordt vanzelf bijgewerkt.</div>
            @elseif ($cohort->backup_status === 'failed')
                <div class="alert alert-danger break-all"><strong>Backup mislukt:</strong> {{ $cohort->backup_error }}</div>
            @endif

            @if ($cohort->backupFile() && $cohort->backup_status !== 'pending')
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <x-buttons.primary href="{{ route('exams.backup', $cohort) }}">Backup downloaden</x-buttons.primary>
                    <span class="text-sm text-muted">
                        {{ number_format($cohort->backup_size / 1048576, 1, ',', '.') }} MB, gemaakt op {{ $cohort->backup_created_at->format('d-m-Y H:i') }}
                    </span>
                </div>
                @if ($cohort->backup_status === 'ready' && $cohort->backup_error)
                    <div class="alert alert-warning text-sm break-all">{{ $cohort->backup_error }}</div>
                @endif
            @endif
        </x-content.section>

        <x-content.section>
            <x-headings.section>Opruimen na het beoordelen</x-headings.section>
            <p>
                Is alles beoordeeld? Verwijder dan de Projects, repositories en teams van dit examen definitief van GitHub.
                Nog op GitHub: {{ $remainingOnGitHub['repositories'] }} repositories, {{ $remainingOnGitHub['projects'] }} Projects en {{ $remainingOnGitHub['teams'] }} teams.
            </p>

            @if (! $cohort->offboarding_started_at)
                <x-content.hint>Sluit eerst het examen af (hierboven).</x-content.hint>
            @elseif ($cohort->cleanup_pending)
                <div class="alert alert-warning">Bezig met verwijderen. Deze pagina wordt vanzelf bijgewerkt.</div>
            @elseif (array_sum($remainingOnGitHub) === 0)
                <x-content.empty>Alles is van GitHub verwijderd.</x-content.empty>
            @else
                <div class="flex flex-col gap-2">
                    <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="cleanupOptions.delete_projects" class="checkbox mt-0.5"> <span>Projects verwijderen</span></label>
                    <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="cleanupOptions.delete_repositories" class="checkbox mt-0.5"> <span>Repositories verwijderen</span></label>
                    <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="cleanupOptions.delete_teams" class="checkbox mt-0.5"> <span>Teams verwijderen</span></label>
                </div>

                <div>
                    <x-buttons.secondary wire:click="previewCleanup">Bekijk wat er verwijderd wordt</x-buttons.secondary>
                </div>
            @endif

            @foreach ($groups->filter(fn ($group) => $group->last_error && $group->status !== \App\Enums\GroupStatus::Failed) as $group)
                <div class="text-sm text-danger-ink break-all"><strong>{{ $group->name }}:</strong> {{ $group->last_error }}</div>
            @endforeach
        </x-content.section>

        @if ($cleanupPlan && $cohort->offboarding_started_at && ! $cohort->cleanup_pending)
            <x-content.section>
                <x-headings.section>Dit wordt definitief verwijderd</x-headings.section>

                @if ($cleanupPlan['warnings'])
                    <div class="alert alert-warning">
                        <ul class="list-disc pl-5 text-sm">
                            @foreach ($cleanupPlan['warnings'] as $warning)
                                <li>{{ $warning }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (! $cleanupPlan['groups'])
                    <x-content.empty>Er is niets te verwijderen met deze keuze.</x-content.empty>
                @else
                    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                        @foreach ($cleanupPlan['groups'] as $entry)
                            <div>
                                <strong>{{ $entry['name'] }}</strong>
                                <ul class="list-disc pl-5 text-muted">
                                    @foreach ($entry['actions'] as $action)
                                        <li>{{ $action['label'] }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>

                    <div class="alert alert-danger text-sm">
                        Dit kan niet ongedaan worden gemaakt vanuit deze app. Een eigenaar van {{ $cohort->organization }} kan een verwijderde
                        repository nog 90 dagen terugzetten op GitHub (organisatie → Settings → Deleted repositories). Een verwijderd team is niet terug te halen.
                    </div>

                    <div class="flex flex-col gap-2 border-t border-ink/10 pt-4">
                        <label for="cleanupConfirmation" class="label">Typ <code>{{ $cohort->name }}</code> om te bevestigen</label>
                        <input id="cleanupConfirmation" type="text" wire:model="cleanupConfirmation" class="input" autocomplete="off">
                        @error('cleanupConfirmation') <p class="text-sm font-medium text-danger-ink">{{ $message }}</p> @enderror
                        <div>
                            <x-buttons.danger wire:click="confirmCleanup">Definitief verwijderen</x-buttons.danger>
                        </div>
                        <x-content.hint>Lukt iets niet, dan kun je dit veilig opnieuw uitvoeren.</x-content.hint>
                    </div>
                @endif
            </x-content.section>
        @endif
    @endif
</x-content.main>
</div>
