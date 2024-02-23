@props([
    'icon' => '⚠',
    'type' => 'info'
])
<div tight {{
    $attributes->class([
        'rounded border p-4',
        'bg-slate-500 border-slate-600 text-slate-200' => $type === 'info',
        'bg-emerald-800 border-emerald-700 text-slate-400' => $type === 'success',
        'bg-red-800 border-red-700 text-slate-400' => $type === 'error',
        'bg-amber-600 border-amber-700 text-white' => $type === 'warning',
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
