<x-content.main>
    @if ($chatMode === 'password')
        <form class="flex flex-col gap-4" wire:submit="tryChatPassword">
            <x-inputs.text class="flex-1" wire:model="chatPassword" name="chatPassword" label="" placeholder="Wachtwoord..." />
            <x-buttons.primary big wire:click="tryChatPassword">Krijg toegang</x-buttons.primary>
        </form>

        <div x-data="{ open: false }" x-on:chat-password-incorrect.window="open = true">
            <div x-show="open" class="fixed inset-0 bg-gray-800 bg-opacity-90 grid place-items-center justify-center text-white">
                <div class="flex flex-col items-center gap-2 rounded bg-gray-800 p-8 shadow">
                    <div class="text-2xl">Onjuist wachtwoord!</div>
                    <p>Let op! Bij te veel onjuiste pogingen wordt je account tijdelijk geblokkeerd.</p>
                    <div class="flex flex-row gap-2 mt-8">
                        <x-buttons.secondary x-on:click="open = false">Sluiten</x-buttons.secondary>
                    </div>
                </div>
            </div>
        </div>

        <div x-data="{ open: false, tryAgainInSeconds: 0 }" x-on:chat-too-many-attempts.window="open = true; tryAgainInSeconds = $event.detail.tryAgainInSeconds">
            <div x-show="open" class="fixed inset-0 bg-gray-800 bg-opacity-90 grid place-items-center justify-center text-white">
                <div class="flex flex-col items-center gap-2 rounded bg-gray-800 p-8 shadow">
                    <div class="text-2xl">Te veel onjuiste pogingen!</div>
                    <p>Je account is tijdelijk geblokkeerd. Je mag het over <span x-text="tryAgainInSeconds"></span> seconden opnieuw proberen.</p>
                    <div class="flex flex-row gap-2 mt-8">
                        <x-buttons.secondary x-on:click="open = false">Sluiten</x-buttons.secondary>
                    </div>
                </div>
            </div>
        </div>
    @else
        <x-gpt :chatMode="$chatMode" />
    @endif
</x-content.main>
