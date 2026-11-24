@props([
    'icon' => '⚠',
    'type' => 'info'
])
<div tight {{
    $attributes->class([
        'alert',
        'alert-secondary' => $type === 'info',
        'alert-success' => $type === 'success',
        'alert-danger' => $type === 'error',
        'alert-warning' => $type === 'warning',
    ])
}}>
    <div class="flex">
        <div class="flex-shrink-0">
            {{ $icon }}
        </div>
        <div class="ml-3">
            <p class="text-sm leading-5">
                {{ $slot }}
            </p>
        </div>
    </div>
</div>
