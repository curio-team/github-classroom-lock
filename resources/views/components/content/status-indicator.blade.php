@props([
    'active' => false,
])

<span class="relative inline-block rounded-lg w-3 h-3 bg-gray-300"
    title="{{ isset($slot) ? $slot : ''}}">
    <span {{
        $attributes->class([
            'status-indicator-activity-bulb absolute inset-0 rounded-lg shadow animate-pulse bg-green-500 shadow-green-500',
            'hidden' => !$active,
        ])
    }}>
    </span>
</span>
