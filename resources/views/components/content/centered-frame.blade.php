@props([
    'big' => false
])

<div {{
    $attributes->class([
        'flex sm:items-center justify-center h-full',
    ])
}}>
    <x-content.section class="w-full flex flex-col p-4 sm:p-8 rounded bg-white max-h-full overflow-y-auto {{ $big ? 'max-w-2xl' : 'max-w-md' }}">
        {{ $slot }}
    </x-content.section>
</div>
