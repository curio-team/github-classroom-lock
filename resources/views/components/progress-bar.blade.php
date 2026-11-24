@props([
    'max' => 100,
    'value' => 0,
    'color' => 'bg-success',
    'hideMaxLabel' => false
])
<div {{
    $attributes->merge([
        'class' => 'flex flex-row gap-2 items-center rounded self-stretch',
    ])
}}>
    <progress class="flex-1 h-2 border border-ink/15 bg-ink/5 rounded accent-primary" max="{{ $max }}" value="{{ $value }}"></progress>
    <div class="text-xs"><span class="progress-value-approximation hidden">±</span><span class="progress-value">{{ number_format_locale($value) }}</span> @unless($hideMaxLabel) / {{ number_format_locale($max) }} @endunless{{ $slot }}</div>
</div>
