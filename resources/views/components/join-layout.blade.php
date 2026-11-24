@props(['title' => 'Aanmelden bij GitHub', 'refresh' => null])
@php
    $background = Vite::asset(\Illuminate\Support\Arr::random([
        'resources/img/backgrounds/andras-vas-Bd7gNnWJBkU-unsplash.jpg',
        'resources/img/backgrounds/lorenzo-herrera-p0j-mE6mGo4-unsplash.jpg',
        'resources/img/backgrounds/lorenzo-herrera-yP89apz2TAA-unsplash.jpg',
        'resources/img/backgrounds/umberto-jXd2FSvcRr8-unsplash.jpg',
        'resources/img/backgrounds/codioful-formerly-gradienta-H5eYSjFGw5M-unsplash.jpg',
    ]));
@endphp
<x-partials.header class="bg-ink bg-cover bg-center" style="background-image: url('{{ $background }}')">
    @if ($refresh)
        <meta http-equiv="refresh" content="{{ (int) $refresh }}">
    @endif
</x-partials.header>

<div class="min-h-screen flex">
    <div class="w-full md:w-auto min-h-screen bg-ink text-white px-6 py-10 sm:px-12 flex flex-col items-center">
        <div class="w-full max-w-[400px] flex flex-col gap-4">
            <x-application-logo class="h-auto! w-full max-w-[200px] mt-4 mb-8" />
            <h1 class="text-2xl font-bold">{{ $title }}</h1>
            {{ $slot }}
        </div>
    </div>
</div>

        @livewireScriptConfig
    </body>
</html>
