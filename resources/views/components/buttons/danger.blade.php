<x-buttons.primary {{ $attributes->merge([
    'class' => '!bg-red-600 !border-red-600 !text-white hover:!bg-red-500 active:!bg-red-700 focus:!ring-red-500',
])}}>
    {{ $slot }}

    @isset($modal)
    <x-slot:modal>
    {{ $modal }}
    </x-slot:modal>
    @endisset
</x-buttons.primary>
