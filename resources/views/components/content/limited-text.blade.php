@props(['limit' => 50])
<div x-data="{ expanded: false }">
    @if (strlen($slot) > $limit)
        <div x-show="!expanded" class="flex flex-row justify-between items-center">
            {{ Str::limit($slot, $limit) }}
            <button @click="expanded = true">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 5.25 7.5 7.5 7.5-7.5m-15 6 7.5 7.5 7.5-7.5" />
                </svg>
            </button>
        </div>
        <div x-show="expanded" x-cloak class="flex flex-row justify-between items-center">
            {{ $slot }}
            <button @click="expanded = false">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 18.75 7.5-7.5 7.5 7.5" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 7.5-7.5 7.5 7.5" />
                </svg>
            </button>
        </div>
    @else
        {{ $slot }}
    @endif
</div>
