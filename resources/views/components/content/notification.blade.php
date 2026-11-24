@props([
'type' => 'info',
])
<div x-data
     {{
     $attributes->class([
    'alert mt-4',
    'alert-secondary' => $type === 'info',
    'alert-success' => $type === 'success',
    'alert-warning' => $type === 'warning',
    'alert-danger' => $type === 'error',
    ]) }}>
    <div class="flex justify-between">
        <div class="ml-3">
            <p class="text-sm leading-5">
                {{ $slot }}
            </p>
        </div>
        <div class="flex-shrink-0">
            <x-buttons.close @click="$root.remove()" />
        </div>
    </div>
</div>