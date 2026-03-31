<x-app-layout>
    <div class="min-h-[60vh] flex items-center justify-center p-6">
        <div class="w-full max-w-2xl text-center">
            <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900 fade-up">
                CurioGPT is verhuisd
            </h1>
            <p class="mt-3 text-base sm:text-lg text-slate-600 fade-up delay-100">
                We zijn verhuisd naar een nieuwe, mooiere plek.
            </p>

            <a href="https://gpt.curio.codes" target="_blank" rel="noopener noreferrer"
               class="mt-8 inline-flex items-center gap-2 justify-center rounded-xl bg-emerald-600 text-white text-lg sm:text-xl font-semibold px-7 py-3.5 hover:bg-emerald-500 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-emerald-300/60 active:translate-y-px transition fade-up delay-200"
               title="Open gpt.curio.codes in een nieuw tabblad">
                Ga naar gpt.curio.codes
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6">
                    <path fill-rule="evenodd" d="M3.75 12a.75.75 0 0 1 .75-.75h12.69l-4.22-4.22a.75.75 0 1 1 1.06-1.06l5.5 5.5a.75.75 0 0 1 0 1.06l-5.5 5.5a.75.75 0 1 1-1.06-1.06l4.22-4.22H4.5a.75.75 0 0 1-.75-.75Z" clip-rule="evenodd" />
                </svg>
            </a>
        </div>
    </div>

    <style>
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-up { animation: fadeUp .5s ease-out both; }
        .delay-100 { animation-delay: .1s; }
        .delay-200 { animation-delay: .2s; }
    </style>
</x-app-layout>
