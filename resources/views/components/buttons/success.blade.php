<x-buttons.primary variant="success" {{ $attributes }}>
    {{ $slot }}

    @isset($modal)
    <x-slot:modal>
    {{ $modal }}
    </x-slot:modal>
    @endisset

    @isset($icon)
    <x-slot:icon>
    {{ $icon }}
    </x-slot:icon>
    @endisset
</x-buttons.primary>
