<x-app-layout>
    <div class="min-h-[60vh] flex items-center justify-center p-6">
        <div class="w-full max-w-2xl text-center flex flex-col gap-4 items-center">
            <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-slate-900">
                GitHub Exam Onboarding
            </h1>
            <p class="text-base sm:text-lg text-slate-600">
                Examinatoren zetten hier examens klaar op GitHub: elke examengroep krijgt een eigen repository, team en Project,
                en tijdens het examen kunnen de groepen worden vergrendeld en ontgrendeld.
            </p>
            <p class="text-slate-600">
                Ben je student? Gebruik de uitnodigingslink die je van je examinator hebt gekregen.
            </p>

            <x-buttons.primary href="{{ route('login') }}" big>Inloggen als examinator</x-buttons.primary>

            <p class="text-sm text-slate-400 mt-6">
                Op zoek naar CurioGPT? Die is verhuisd naar
                <a class="underline" href="https://gpt.curio.codes" target="_blank" rel="noopener noreferrer">gpt.curio.codes</a>.
            </p>
        </div>
    </div>
</x-app-layout>
