@props([
    'tight' => false,
    'row' => false,
    'wrap' => false
])
<div {{
    $attributes->class([
        'flex',
        'gap-4' => !$tight,
        'gap-2' => $tight,
        'flex-col' => !$row,
        'flex-row items-center' => $row,
        'flex-wrap' => $wrap
    ])
}}>
    {{ $slot }}
</div>
