@php
    $title = match ($result->state) {
        'joined' => 'Je bent toegevoegd! 🎉',
        'processing' => 'Even geduld…',
        'action_required' => 'Nog één stap',
        default => 'Dat lukte niet',
    };
@endphp
<x-join-layout :title="$title" :refresh="$result->state === 'processing' ? 5 : null">
    <p>{{ $result->message }}</p>

    @if ($result->state === 'joined' && $group?->isLocked())
        <div class="rounded-lg bg-warning px-5 py-3 font-medium text-ink">
            🔒 Je groep is op dit moment vergrendeld. Je kunt weer bij je repository en Project zodra de examinator het examen ontgrendelt.
        </div>
    @endif

    @if ($result->state === 'joined' && $group)
        <p>Je zit in <strong>{{ $group->name }}</strong>. Hier vind je alles van je groep:</p>
        <div class="flex flex-col gap-2">
            @if ($group->repository_url)
                <a href="{{ $group->repository_url }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-white/20 px-4 py-3 text-white transition-colors duration-300 hover:border-primary hover:bg-white/5 hover:text-white">
                    <strong>Repository</strong><br><span class="text-sm text-white/60">{{ $cohort->organization }}/{{ $group->repository_name }}</span>
                </a>
            @endif
            @if ($group->project_url)
                <a href="{{ $group->project_url }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-white/20 px-4 py-3 text-white transition-colors duration-300 hover:border-primary hover:bg-white/5 hover:text-white">
                    <strong>Project</strong><br><span class="text-sm text-white/60">Het planbord van je groep</span>
                </a>
            @endif
            <a href="https://github.com/orgs/{{ $cohort->organization }}/teams/{{ $group->team_slug }}" target="_blank" rel="noopener noreferrer" class="rounded-lg border border-white/20 px-4 py-3 text-white transition-colors duration-300 hover:border-primary hover:bg-white/5 hover:text-white">
                <strong>Team</strong><br><span class="text-sm text-white/60">{{ $group->team_slug }}</span>
            </a>
        </div>
    @endif

    @if ($result->actionUrl)
        <x-buttons.primary href="{{ $result->actionUrl }}" target="_blank" rel="noopener noreferrer" class="self-start">
            {{ $result->actionLabel ?? 'Ga naar GitHub' }}
        </x-buttons.primary>
    @endif

    @if ($result->state !== 'joined')
        <a href="{{ route('join.status', $token) }}" class="self-start text-sm font-bold">Pagina verversen</a>
    @endif

    <p class="text-xs text-white/50">Ingelogd als {{ $student->github_login }}.</p>
</x-join-layout>
