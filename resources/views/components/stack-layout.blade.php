@props([
    'row' => false,
])
<div {{
    $attributes->class([
        'flex gap-4',
        'flex-row' => $row,
        'flex-col' => ! $row,
    ])
}}>
    {{ $slot }}
</div>
