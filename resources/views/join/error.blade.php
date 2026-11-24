<x-join-layout :title="$title">
    <p>{{ $message }}</p>

    @isset($retryUrl)
        <x-buttons.primary href="{{ $retryUrl }}" class="self-start">Opnieuw proberen</x-buttons.primary>
    @endisset
</x-join-layout>
