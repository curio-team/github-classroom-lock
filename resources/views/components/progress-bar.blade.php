@props([
    'max' => 100,
    'value' => 0,
    'color' => 'bg-emerald-400',
    'hideMaxLabel' => false
])
<div {{
    $attributes->merge([
        'class' => 'flex flex-row gap-2 items-center rounded self-stretch',
    ])
}}>
    <progress class="flex-1 h-2 border border-slate-400 bg-slate-200 rounded" max="{{ $max }}" value="{{ $value }}"></progress>
    <div class="text-xs"><span class="progress-value-approximation hidden">±</span><span class="progress-value">{{ number_format($value) }}</span> @unless($hideMaxLabel) / {{ number_format($max) }} @endunless{{ $slot }}</div>
</div>
