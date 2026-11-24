<div @if ($isBusy) wire:poll.3s @endif>
<x-content.main>
    {{-- Header + steps --}}
    <x-content.section>
        <a href="{{ route('exams.index') }}" class="text-sm text-gray-500 underline">← Examens</a>
        <h2 class="text-2xl font-semibold">{{ $cohort->name }}</h2>

        <nav class="grid grid-cols-2 sm:grid-cols-5 gap-2 mt-2">
            @foreach ($steps as $key => $label)
                <button type="button" wire:click="$set('step', '{{ $key }}')"
                    @class([
                        'text-left rounded-md border px-3 py-2 text-sm transition',
                        'border-gray-900 bg-gray-900 text-white' => $step === $key,
                        'border-gray-200 hover:border-gray-400' => $step !== $key,
                    ])>
                    <span class="block text-xs opacity-70">Stap {{ $loop->iteration }} @if ($progress[$key]) ✓ @endif</span>
                    <span class="font-semibold">{{ $label }}</span>
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
        <div class="rounded-md bg-amber-50 border border-amber-200 p-4 text-amber-900">
            Het examen wordt afgesloten (gestart {{ $cohort->offboarding_started_at->format('d-m-Y H:i') }}). Deze pagina wordt vanzelf bijgewerkt.
        </div>
    @elseif ($cohort->offboarded_at)
        <div class="rounded-md bg-slate-100 border border-slate-200 p-4 text-slate-700">
            Dit examen is afgesloten op {{ $cohort->offboarded_at->format('d-m-Y H:i') }}.
        </div>
    @endif

    @if ($orgProblems)
        <div class="rounded-md bg-red-50 border border-red-200 p-4 text-red-900 flex flex-col gap-2">
            <strong>Controleer de GitHub-organisatie</strong>
            @foreach ($orgProblems as $check)
                <div>
                    <span class="font-semibold">{{ $check['label'] }}:</span> {{ $check['message'] }}
                    @if ($check['key'] === 'base_permission' && $check['level'] === 'error')
                        <button type="button" class="ml-2 underline" x-on:click="if (confirm('Basisrechten van de hele organisatie op No permission zetten?')) $wire.fixBasePermission()">Nu oplossen</button>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    {{-- 1. Students --}}
    @if ($step === 'students')
        <x-content.section>
            <x-headings.section>Welke studenten doen mee, en in welke groep?</x-headings.section>
            <p>
                Upload een CSV-bestand met de kolommen <code>github_username</code> en <code>group</code>
                (een export uit Excel met puntkomma's werkt ook), of plak de lijst hieronder.
            </p>
            <pre class="bg-gray-50 rounded p-2 text-xs">github_username,group
octocat,Groep 1
monalisa,Groep 1
hubot,Groep 2</pre>

            <form wire:submit="previewRoster" class="flex flex-col gap-2">
                <input type="file" wire:model="rosterFile" accept=".csv,.txt,.tsv,text/csv">
                @error('rosterFile') <p class="text-red-500 italic">{{ $message }}</p> @enderror
                <details>
                    <summary class="text-sm text-gray-600 cursor-pointer">…of plak de lijst</summary>
                    <textarea wire:model="rosterText" rows="8" class="w-full p-2 border border-gray-300 rounded-md font-mono text-xs" placeholder="github_username,group"></textarea>
                </details>
                <div>
                    <button type="submit" class="rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700" wire:loading.attr="disabled">
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
                    <span class="rounded bg-gray-100 px-2 py-1">{{ count($preview->rows) }} geldige studenten</span>
                    <span class="rounded bg-green-100 px-2 py-1">{{ count($preview->changes['new']) }} nieuw</span>
                    <span class="rounded bg-blue-100 px-2 py-1">{{ count($preview->changes['moved']) }} andere groep</span>
                    <span class="rounded bg-red-100 px-2 py-1">{{ count($preview->changes['removed']) }} verwijderd</span>
                    <span class="rounded bg-gray-100 px-2 py-1">{{ count($preview->groupCounts) }} groepen</span>
                </div>

                @if ($preview->errors)
                    <div class="rounded-md bg-red-50 border border-red-200 p-3">
                        <strong class="text-red-800">{{ count($preview->errors) }} fout(en): pas het bestand aan en controleer opnieuw.</strong>
                        <ul class="list-disc pl-5 text-sm text-red-800">
                            @foreach ($preview->errors as $error)
                                <li>@if ($error['line']) Regel {{ $error['line'] }}: @endif {{ $error['message'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($preview->warnings)
                    <div class="rounded-md bg-amber-50 border border-amber-200 p-3">
                        <strong class="text-amber-800">Let op</strong>
                        <ul class="list-disc pl-5 text-sm text-amber-800">
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
                                <li>{{ $name }}: {{ $count }} @if (in_array($name, $preview->newGroups)) <span class="text-green-700">(nieuw)</span> @endif</li>
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

        @if ($groups->isNotEmpty() && ! $preview)
            <x-content.section>
                <div class="flex justify-between items-center">
                    <x-headings.section>Huidige groepen</x-headings.section>
                    <x-buttons.primary wire:click="$set('step', 'prepare')">Volgende: klaarzetten →</x-buttons.primary>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
                    @foreach ($groups as $group)
                        <div class="rounded border p-3">
                            <strong>{{ $group->name }}</strong>
                            <div class="text-gray-600">{{ $group->students->pluck('github_login')->implode(', ') ?: 'geen studenten' }}</div>
                        </div>
                    @endforeach
                </div>
            </x-content.section>
        @endif
    @endif

    {{-- 2. Prepare --}}
    @if ($step === 'prepare' && $organizationProjects === false)
        <div class="rounded-md bg-red-50 border border-red-200 p-4 text-red-900 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
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
            <p class="text-sm text-gray-600">
                Elke groep krijgt een eigen team, een private repository en een Project, die alleen de groep zelf kan zien.
                Je kunt dit veilig opnieuw doen: wat al bestaat wordt hergebruikt.
            </p>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="py-2 pr-4">Groep</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Op GitHub</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groups as $group)
                            <tr class="border-t align-top">
                                <td class="py-2 pr-4">
                                    <strong>{{ $group->name }}</strong>
                                    <div class="text-xs text-gray-500">{{ $group->students_count }} studenten</div>
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $group->status->color() }}">{{ $group->status->label() }}</span>
                                    @if ($group->status !== \App\Enums\GroupStatus::Provisioned)
                                        <div class="flex flex-wrap gap-x-2 mt-1">
                                            @foreach (\App\Enums\ProvisioningStep::cases() as $provisioningStep)
                                                <span @class([
                                                    'text-xs',
                                                    'text-green-700' => $group->hasCompleted($provisioningStep),
                                                    'text-red-700 font-semibold' => $group->failed_step === $provisioningStep->value,
                                                    'text-gray-300' => ! $group->hasCompleted($provisioningStep) && $group->failed_step !== $provisioningStep->value,
                                                ])>{{ $group->hasCompleted($provisioningStep) ? '✓' : ($group->failed_step === $provisioningStep->value ? '✗' : '○') }} {{ $provisioningStep->label() }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if ($group->last_error)
                                        <div class="text-xs text-red-700 mt-1 break-all">{{ $group->last_error }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    <div class="flex flex-wrap gap-x-3">
                                        @if ($group->repository_url)
                                            <a class="underline" target="_blank" href="{{ $group->repository_url }}">Repository</a>
                                        @endif
                                        @if ($group->project_url)
                                            <a class="underline" target="_blank" href="{{ $group->project_url }}">Project</a>
                                        @endif
                                        @if ($group->team_slug)
                                            <a class="underline" target="_blank" href="https://github.com/orgs/{{ $cohort->organization }}/teams/{{ $group->team_slug }}">Team</a>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    @if (! $cohort->offboarding_started_at && $group->status === \App\Enums\GroupStatus::Failed)
                                        <x-buttons.secondary tight wire:click="provisionGroup({{ $group->id }})">Opnieuw proberen</x-buttons.secondary>
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
            <p class="text-sm text-gray-600">
                Deel deze link met de studenten (bijv. op het bord of in Teams). Ze loggen in met GitHub en komen automatisch in hun eigen groep.
                Alleen studenten van de lijst kunnen meedoen: een doorgestuurde link geeft anderen geen toegang.
            </p>

            <div class="flex items-center gap-2">
                @include('livewire.partials.invite-status', ['status' => $cohort->inviteLinkStatus()])
                @if ($cohort->invite_expires_at && $cohort->inviteLinkStatus() !== 'none')
                    <span class="text-sm text-gray-500">geldig t/m {{ $cohort->invite_expires_at->format('d-m-Y') }}</span>
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
                    <input type="text" readonly x-ref="link" value="{{ $cohort->inviteUrl() }}" class="grow p-2 border border-gray-300 rounded-md bg-gray-50 text-sm" x-on:click="$el.select()">
                    <button type="button" x-on:click="copy()" class="shrink-0 rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700 focus:outline-none focus:ring focus:ring-offset-2">
                        <span x-text="copied ? 'Gekopieerd!' : 'Kopiëren'">Kopiëren</span>
                    </button>
                </div>
            @endif

            @if (! $cohort->offboarding_started_at)
                <details @if (! $cohort->inviteLinkIsUsable()) open @endif>
                    <summary class="cursor-pointer text-sm text-gray-600">{{ $cohort->inviteLinkIsUsable() ? 'Link aanpassen' : 'Link maken' }}</summary>
                    <div class="flex flex-col sm:flex-row sm:items-end gap-2 mt-2">
                        <div class="flex flex-col gap-1">
                            <label for="inviteExpiresAt" class="text-sm font-medium">Geldig tot en met</label>
                            <input id="inviteExpiresAt" type="date" wire:model="inviteExpiresAt" class="p-2 border border-gray-300 rounded-md">
                            @error('inviteExpiresAt') <p class="text-red-500 italic">{{ $message }}</p> @enderror
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
                <table class="w-full text-sm">
                    <thead class="text-left text-gray-500">
                        <tr>
                            <th class="py-2 pr-4">Student</th>
                            <th class="py-2 pr-4">Groep</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($students->sortBy(fn ($s) => [$s->status->value === 'joined' ? 1 : 0, $s->github_login]) as $student)
                            <tr class="border-t align-top" wire:key="student-{{ $student->id }}">
                                <td class="py-2 pr-4">
                                    <a class="underline" target="_blank" href="https://github.com/{{ $student->github_login }}">{{ $student->github_login }}</a>
                                </td>
                                <td class="py-2 pr-4">{{ $student->group?->name ?? '—' }}</td>
                                <td class="py-2 pr-4">
                                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $student->status->color() }}">{{ $student->status->label() }}</span>
                                    @if ($student->failure_reason)
                                        <div class="text-xs text-red-700 mt-1 break-all">{{ $student->failure_reason }}</div>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right">
                                    @if (in_array($student->status, [\App\Enums\StudentStatus::Failed, \App\Enums\StudentStatus::Invited, \App\Enums\StudentStatus::Imported]) && $student->last_attempt_at && ! $cohort->offboarding_started_at)
                                        <x-buttons.secondary tight wire:click="retryStudent({{ $student->id }})" wire:loading.attr="aria-disabled">Opnieuw proberen</x-buttons.secondary>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><x-content.empty>Nog geen studenten. Voer ze in bij stap 1.</x-content.empty></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-content.hint>
                Nog niet ingelogd: de student heeft de link nog niet geopend. Uitgenodigd: de student moet de uitnodiging nog accepteren op
                github.com/orgs/{{ $cohort->organization }}/invitation. Toegevoegd: de student zit in de eigen groep.
            </x-content.hint>

            @if ($orgChecks)
                <details>
                    <summary class="cursor-pointer text-sm text-gray-600">Controles van de GitHub-organisatie</summary>
                    <ul class="flex flex-col gap-1 mt-2 text-sm">
                        @foreach ($orgChecks as $check)
                            <li>
                                {{ ['ok' => '✓', 'warning' => '⚠', 'error' => '✗', 'info' => 'ℹ'][$check['level']] }}
                                <strong>{{ $check['label'] }}:</strong> {{ $check['message'] }}
                            </li>
                        @endforeach
                    </ul>
                    <button type="button" wire:click="refreshChecks" class="mt-2 text-sm underline">Vernieuwen</button>
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
                    <p class="text-sm text-gray-600">
                        Vergrendel aan het eind van de dag, ontgrendel bij de start. Een vergrendelde groep kan niet bij zijn repository en Project;
                        de studenten blijven wel in hun team, zodat toegewezen taken behouden blijven.
                    </p>
                </div>
                @if ($activeGroupCount > 0 && ! $cohort->offboarding_started_at)
                    <div class="flex gap-2 shrink-0">
                        <x-buttons.danger big x-on:click="if (confirm('Alle groepen vergrendelen?')) $wire.lockAll()">🔒 Alles vergrendelen</x-buttons.danger>
                        <x-buttons.primary big x-on:click="if (confirm('Alle groepen ontgrendelen?')) $wire.unlockAll()">🔓 Alles ontgrendelen</x-buttons.primary>
                    </div>
                @endif
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                @forelse ($groups->whereNull('archived_at') as $group)
                    <div wire:key="lock-{{ $group->id }}" @class([
                        'rounded-lg border-2 p-4 flex flex-col gap-2',
                        'border-red-300 bg-red-50' => $group->isLocked(),
                        'border-green-300 bg-green-50' => ! $group->isLocked(),
                    ])>
                        <div class="flex justify-between items-start">
                            <strong>{{ $group->name }}</strong>
                            <span class="text-sm font-semibold">
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
                                    <span class="text-xs {{ $student->status->value === 'joined' ? 'text-green-700' : 'text-amber-700' }}">{{ $student->status->label() }}</span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($group->lock_error)
                            <div class="text-xs text-red-700 break-all">{{ $group->lock_error }}</div>
                        @endif

                        @if (! $cohort->offboarding_started_at && ! $group->lock_pending)
                            <div class="mt-auto">
                                @if ($group->isLocked())
                                    <x-buttons.primary wire:click="unlockGroup({{ $group->id }})">Ontgrendelen</x-buttons.primary>
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

            <details class="border-t pt-3">
                <summary class="cursor-pointer text-sm text-gray-600">Projects voor de hele organisatie uitzetten</summary>
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
                <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="offboardOptions.remove_team_memberships" class="mt-1"> <span>Studenten uit hun groepsteam halen</span></label>
                <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="offboardOptions.remove_org_membership" class="mt-1"> <span>Studenten uit {{ $cohort->organization }} halen (openstaande uitnodigingen worden ingetrokken)</span></label>
                <label class="flex gap-2 items-start"><input type="checkbox" wire:model.live="offboardOptions.archive_repositories" class="mt-1"> <span>Repositories archiveren</span></label>
            </div>

            <div>
                <x-buttons.secondary wire:click="previewOffboarding">Bekijk wat er gaat gebeuren</x-buttons.secondary>
            </div>
        </x-content.section>

        @if ($offboardPlan)
            <x-content.section>
                <x-headings.section>Dit gaat er gebeuren</x-headings.section>

                @if ($offboardPlan['warnings'])
                    <div class="rounded-md bg-amber-50 border border-amber-200 p-3">
                        <ul class="list-disc pl-5 text-sm text-amber-800">
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
                                        <ul class="list-disc pl-5 text-gray-600">
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
                            <ul class="list-disc pl-5 text-gray-600">
                                @foreach ($offboardPlan['groups'] as $entry)
                                    @foreach ($entry['actions'] as $action)
                                        <li>{{ $action['label'] }}</li>
                                    @endforeach
                                @endforeach
                            </ul>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 border-t pt-4">
                        <label for="offboardConfirmation" class="font-medium">Typ <code>{{ $cohort->name }}</code> om te bevestigen</label>
                        <input id="offboardConfirmation" type="text" wire:model="offboardConfirmation" class="p-2 border border-gray-300 rounded-md" autocomplete="off">
                        @error('offboardConfirmation') <p class="text-red-500 italic">{{ $message }}</p> @enderror
                        <div>
                            <x-buttons.danger wire:click="confirmOffboarding">Examen afsluiten</x-buttons.danger>
                        </div>
                        <x-content.hint>De uitnodigingslink wordt ingetrokken. Lukt iets niet, dan kun je dit veilig opnieuw uitvoeren.</x-content.hint>
                    </div>
                @endif
            </x-content.section>
        @endif
    @endif
</x-content.main>
</div>
