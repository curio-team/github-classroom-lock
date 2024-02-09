@props([
    'max' => 100,
    'value' => 0,
    'color' => 'bg-emerald-400'
])
<div {{
    $attributes->merge([
        'class' => 'flex flex-col gap-2 rounded border-slate-400 border'
    ])
}}>
    <div class="flex flex-col gap-2 p-4">
        <div class="flex flex-row justify-between">
            <span class="uppercase font-semibold">{{ $slot }}</span>
            <span class="text-slate-300">{{ $value }} / {{ $max }}</span>
        </div>
        <div class="h-4 bg-slate-200 rounded">
            <div class="{{ $color }} h-full rounded" style="width: {{ $value / $max * 100 }}%"></div>
        </div>
    </div>
</div>
