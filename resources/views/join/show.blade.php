<x-join-layout :title="$cohort->name">
    <p>
        Je bent ingedeeld in een examengroep. Log in met je GitHub-account om bij de repository en het Project
        van je groep te komen.
    </p>

    <ul class="list-disc pl-5 text-sm text-white/70 flex flex-col gap-1">
        <li>Log in met het GitHub-account dat je aan je examinator hebt doorgegeven.</li>
        <li>GitHub vraagt je om deze app toegang te geven. Die toegang gebruiken we alleen om je uitnodiging te accepteren; we bewaren hem niet.</li>
        @if ($cohort->invite_expires_at)
            <li>Deze link werkt tot {{ $cohort->invite_expires_at->format('d-m-Y') }}.</li>
        @endif
    </ul>

    @if ($configured)
        <form method="POST" action="{{ route('join.redirect', $token) }}">
            @csrf
            <button type="submit" class="mt-2 w-full flex items-center justify-center gap-2 rounded-lg border border-primary bg-primary px-6 py-4 text-xl font-bold text-white transition-colors duration-300 hover:border-primary-dark hover:bg-primary-dark cursor-pointer">
                <svg class="h-5 w-5" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                Inloggen met GitHub
            </button>
        </form>
    @else
        <p class="rounded-lg bg-danger/15 border border-danger/40 px-5 py-3 font-medium text-red-200">Inloggen met GitHub is nog niet ingesteld. Laat dit weten aan je examinator.</p>
    @endif
</x-join-layout>
