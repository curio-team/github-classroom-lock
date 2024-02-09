@props([
    'tight' => false,
])
<div {{
    $attributes->class([
        'flex flex-col bg-white shadow sm:rounded-lg text-gray-900',
        'p-4 sm:p-8 gap-2' => !$tight,
    ])
}}>
    {{ $slot }}
</div>
