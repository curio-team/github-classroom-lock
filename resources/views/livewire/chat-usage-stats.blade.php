<div class="flex flex-col gap-2 rounded border-slate-400 border"
    x-data="{}"
    x-on:app-chat-received.window="$wire.$refresh()">
    <x-headings.page class="text-center p-4">Your remaining chats this hour</x-heading.h1>

    <x-content.stack-layout row class="items-stretch bg-gray-100 p-4">
        @foreach($chatLimits as $gpt => $chatLimit)
            <div class="flex flex-col gap-2 p-4 grow text-center">
                <h2 class="uppercase font-semibold">{{ $gpt }}</h2>

                <p class="text-4xl flex flex-col gap-2">
                @if ($chatLimit === 0)
                    Out of chats
                @elseif ($chatLimit === -1)
                    Unlimited
                    <span class="text-sm italic">while supplies last</span>
                @else
                    {{ $chatLimit }}
                @endif
                </p>
            </div>
        @endforeach
    </x-content.stack-layout>

    <div class="flex flex-col gap-2 p-4 grow text-center">
        <h2 class="uppercase font-semibold">Time until reset</h2>

        <div class="text-4xl flex flex-row justify-center gap-2"
            id="timeReset">
            Calculating...
        </div>

        <script>
            const timeReset = document.getElementById('timeReset');
            const serverTime = new Date('{{ now() }}').getTime();
            const resetTime = new Date('{{ user()->chat_limits_reset }}').getTime();
            const localTime = new Date().getTime();
            const timeDifference = localTime - serverTime;

            setInterval(() => {
                const currentTime = new Date().getTime() - timeDifference;
                const timeUntilReset = resetTime - currentTime;

                if (timeUntilReset < 0) {
                    timeReset.innerText = 'Now';
                } else {
                    const hours = Math.floor((timeUntilReset % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((timeUntilReset % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((timeUntilReset % (1000 * 60)) / 1000);

                    timeReset.innerHTML = `
                        <span class="text-4xl">${hours > 0 ? (hours+'h') : '<span class="text-slate-300">0h</span>'}</span>
                        <span class="text-4xl">${minutes > 0 ? (minutes+'m') : '<span class="text-slate-300">0m</span>'}</span>
                        <span class="text-4xl">${seconds}s</span>
                    `;
                }
            }, 1000);
        </script>
    </div>
</div>
