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
        <div class="rounded-md bg-amber-50 border border-amber-200 p-3 text-amber-900">
            🔒 Je groep is op dit moment vergrendeld. Je kunt weer bij je repository en Project zodra de examinator het examen ontgrendelt.
        </div>
    @endif

    @if ($result->state === 'joined' && $group)
        <p>Je zit in <strong>{{ $group->name }}</strong>. Hier vind je alles van je groep:</p>
        <div class="flex flex-col gap-2">
            @if ($group->repository_url)
                <a href="{{ $group->repository_url }}" target="_blank" rel="noopener noreferrer" class="rounded-md border border-gray-300 px-4 py-3 hover:bg-gray-50">
                    <strong>Repository</strong><br><span class="text-sm text-gray-600">{{ $cohort->organization }}/{{ $group->repository_name }}</span>
                </a>
            @endif
            @if ($group->project_url)
                <a href="{{ $group->project_url }}" target="_blank" rel="noopener noreferrer" class="rounded-md border border-gray-300 px-4 py-3 hover:bg-gray-50">
                    <strong>Project</strong><br><span class="text-sm text-gray-600">Het planbord van je groep</span>
                </a>
            @endif
            <a href="https://github.com/orgs/{{ $cohort->organization }}/teams/{{ $group->team_slug }}" target="_blank" rel="noopener noreferrer" class="rounded-md border border-gray-300 px-4 py-3 hover:bg-gray-50">
                <strong>Team</strong><br><span class="text-sm text-gray-600">{{ $group->team_slug }}</span>
            </a>
        </div>
    @endif

    @if ($result->actionUrl)
        <a href="{{ $result->actionUrl }}" target="_blank" rel="noopener noreferrer" class="self-start rounded-md bg-gray-900 px-4 py-2 text-white font-semibold hover:bg-gray-700">
            {{ $result->actionLabel ?? 'Ga naar GitHub' }}
        </a>
    @endif

    @if ($result->state !== 'joined')
        <a href="{{ route('join.status', $token) }}" class="self-start underline text-sm">Pagina verversen</a>
    @endif

    <p class="text-xs text-gray-400">Ingelogd als {{ $student->github_login }}.</p>
</x-join-layout>
