<x-join-layout :title="$title">
    <p>{{ $message }}</p>

    @isset($retryUrl)
        <a href="{{ $retryUrl }}" class="self-start rounded-md bg-gray-900 px-4 py-2 text-white font-semibold hover:bg-gray-700">Opnieuw proberen</a>
    @endisset
</x-join-layout>
