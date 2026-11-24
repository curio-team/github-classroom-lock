<x-join-layout title="Je staat niet op de lijst">
    <p>
        Je bent ingelogd als <strong>{{ $login }}</strong>, maar dit GitHub-account staat niet op de lijst van
        <strong>{{ $cohort->name }}</strong>. Er is niets aan je account veranderd.
    </p>
    <ul class="list-disc pl-5 text-sm text-white/70 flex flex-col gap-1">
        <li>Heb je meerdere GitHub-accounts? Log op github.com uit en probeer het opnieuw met het juiste account.</li>
        <li>Klopt het account wel? Geef je GitHub-gebruikersnaam door aan je examinator, zodat die je aan de lijst kan toevoegen.</li>
    </ul>
    <a href="https://github.com/logout" target="_blank" rel="noopener noreferrer" class="self-start text-sm font-bold">Uitloggen op GitHub</a>
</x-join-layout>
