<x-buttons.primary {{ $attributes->merge([
    'class' => '!bg-white !border-gray-300 hover:!bg-gray-100 !text-gray-700 !ring-indigo-500'
])}}>
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
