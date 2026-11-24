@props([
    'tight' => false,
])
<div {{
    $attributes->class([
        'flex flex-col bg-white shadow-card rounded-lg text-ink',
        'p-5 sm:p-8 gap-3' => !$tight,
    ])
}}>
    {{ $slot }}
</div>
