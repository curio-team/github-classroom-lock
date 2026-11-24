@props(['title' => 'Aanmelden bij GitHub', 'refresh' => null])
<x-partials.header>
    @if ($refresh)
        <meta http-equiv="refresh" content="{{ (int) $refresh }}">
    @endif
</x-partials.header>

<div class="min-h-screen bg-gray-50 text-gray-900 flex items-start sm:items-center justify-center p-4">
    <div class="w-full max-w-lg bg-white shadow sm:rounded-lg p-6 sm:p-8 flex flex-col gap-4">
        <div class="max-w-[100px]">
            <x-logos.light-logo />
        </div>
        <h1 class="text-2xl font-semibold">{{ $title }}</h1>
        {{ $slot }}
    </div>
</div>

<x-partials.footer />
