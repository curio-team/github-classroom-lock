@props([
    'active' => false,
])

<span class="relative inline-block rounded-lg w-3 h-3 bg-ink/15"
    title="{{ isset($slot) ? $slot : ''}}">
    <span {{
        $attributes->class([
            'status-indicator-activity-bulb absolute inset-0 rounded-lg shadow animate-pulse bg-success shadow-success',
            'hidden' => !$active,
        ])
    }}>
    </span>
</span>
