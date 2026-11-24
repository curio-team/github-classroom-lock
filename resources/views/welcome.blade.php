<x-app-layout>
    <div class="min-h-[60vh] flex items-center justify-center p-6">
        <div class="w-full max-w-2xl text-center flex flex-col gap-4 items-center">
            <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-ink">
                GitHub Exam Onboarding
            </h1>
            <p class="text-base sm:text-lg text-muted">
                Examinatoren zetten hier examens klaar op GitHub: elke examengroep krijgt een eigen repository, team en Project,
                en tijdens het examen kunnen de groepen worden vergrendeld en ontgrendeld.
            </p>
            <p class="text-muted">
                Ben je student? Gebruik de uitnodigingslink die je van je examinator hebt gekregen.
            </p>

            <x-buttons.primary href="{{ route('login') }}" big>Inloggen als examinator</x-buttons.primary>

            <p class="text-sm text-muted mt-6">
                Op zoek naar CurioGPT? Die is verhuisd naar
                <a class="font-bold" href="https://gpt.curio.codes" target="_blank" rel="noopener noreferrer">gpt.curio.codes</a>.
            </p>
        </div>
    </div>
</x-app-layout>
