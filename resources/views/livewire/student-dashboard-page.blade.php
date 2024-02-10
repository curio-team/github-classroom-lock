<x-content.main>
    <x-gpt :isChatActive="$isChatActive" />

    @if ($isChatActive)
        @livewire('chat-usage-stats')
    @endif
</x-content.main>
