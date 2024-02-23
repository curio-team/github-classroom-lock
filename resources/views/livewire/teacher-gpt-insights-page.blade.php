<x-content.main>
    <x-content.section>
        <x-headings.page>CurioGPT Chat Tokens</x-headings.page>
        <p>Per student, per dag, zijn de volgende hoeveelheid <x-buttons.link href="https://platform.openai.com/tokenizer" target="blank">tokens</x-buttons.link> beschikbaar:</p>
        <ul class="list-disc list-inside">
            @foreach ($chatTokensMaxPerUserPerModelPerDay as $model => $modelChatTokensMax)
                <li class="mt-2">
                    <strong>{{ $model }}:</strong> @if($modelChatTokensMax > -1) {{ $modelChatTokensMax }} tokens @else Onbeperkt @endif
                </li>
            @endforeach
        </ul>

        <p class="italic">Studenten vinden hun beschikbare hoeveelheid tokens aangeduid bij het relevante model.</p>
    </x-content.section>

    <x-content.section>
        <div class="flex flex-row justify-between items-center">
            <x-headings.page>Gebruikers</x-headings.page>
            <div>
                <x-inputs.text wire:model.live.debounce.250ms="searchUser" name="search-user" label="Zoeken:" placeholder="Gebruikersnaam of email..." />
            </div>
        </div>

        <table class="w-full"
            x-data="{ cheatActive: false }"
            x-on:keydown.window="if (event.altKey && event.code === 'KeyC') { cheatActive = true; }">
            <thead>
                <tr>
                    <th class="p-2 text-left">Gebruikersnaam</th>
                    <th class="p-2 text-left">Token Limieten </th>
                    <th class="p-2 text-left"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr class="@if($loop->even) bg-gray-100 @endif">
                        <td class="p-2 whitespace-nowrap w-full">{{ $user->name }}</td>
                        <td class="p-2 whitespace-nowrap">
                            @if ($user->chats_remaining)
                                @foreach ($user->chats_remaining as $model => $limit)
                                    @if ($chatTokensMaxPerUserPerModelPerDay[$model] > -1)
                                        <div class="flex flex-row gap-2 items-center">
                                            <span class="text-xs">{{ $model }}:</span>
                                            <x-progress-bar class="w-1/2" max="{{ $chatTokensMaxPerUserPerModelPerDay[$model] }}" value="{{ $limit }}" color="bg-emerald-400" hideMaxLabel />
                                        </div>
                                    @else
                                        <span class="text-xs">{{ $model }}: Onbeperkt</span>
                                    @endif
                                @endforeach
                            @else
                                Nog geen gebruik gemaakt van CurioGPT
                            @endif
                        </td>
                        <td class="p-2 whitespace-nowrap">
                            <x-buttons.primary wire:click="resetTokens('{{ $user->id }}')"
                                x-cloak
                                x-show="cheatActive">
                                Reset Token Limiet
                            </x-buttons.primary>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="p-2">Geen gebruikers gevonden.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $users->links() }}
    </x-content.section>

    <x-content.section>
        <div class="flex flex-row justify-between items-center">
            <x-headings.page>CurioGPT Chat Berichten</x-headings.page>
            <div>
                <x-inputs.text wire:model.live.debounce.250ms="search" name="search" label="Zoeken:" placeholder="Zoekopdracht..." />
            </div>
        </div>

        <table class="w-full">
            <thead>
                <tr>
                    <th class="p-2 text-left">Datum</th>
                    <th class="p-2 text-left">Gebruiker</th>
                    <th class="p-2 text-left">Prompt</th>
                    <th class="p-2 text-left">Antwoord</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($chatLogs as $chatLog)
                    <tr class="@if($loop->even) bg-gray-100 @endif">
                        <td class="p-2 whitespace-nowrap text-xs">{{ $chatLog->created_at->format('d M H:i') }}</td>
                        <td class="p-2 whitespace-nowrap">{{ $chatLog->user->name }}</td>
                        <td class="p-2 w-2/3">
                            <x-content.limited-text>{{ $chatLog->prompt }}</x-content.limited-text>
                        </td>
                        <td class="p-2 w-1/3">
                            <x-content.limited-text limit="10">{{ $chatLog->response }}</x-content.limited-text>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="p-2">Geen chat logs gevonden.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $chatLogs->links() }}
    </x-content.section>

    <div wire:loading>
        <div class="fixed inset-0 bg-gray-800 bg-opacity-75 grid place-items-center justify-center text-white">
            <div class="flex flex-col items-center gap-2 rounded bg-gray-800 p-4 shadow">
                <div class="animate-spin rounded-full h-16 w-16 border-t-2 border-b-2 border-gray-200"></div>
                Aan het laden...
            </div>
        </div>
    </div>

    <div x-data="{ open: false, userName: '' }" x-on:user-tokens-reset.window="open = true; userName = $event.detail.userName">
        <div x-show="open" class="fixed inset-0 bg-gray-800 bg-opacity-90 grid place-items-center justify-center text-white">
            <div class="flex flex-col items-center gap-2 rounded bg-gray-800 p-8 shadow">
                <div class="text-2xl">Token limiet gereset voor <span x-text="userName"></span></div>
                <div class="flex flex-row gap-2 mt-8">
                    <x-buttons.secondary x-on:click="open = false">Sluiten</x-buttons.secondary>
                </div>
            </div>
        </div>
    </div>
</x-content.main>
