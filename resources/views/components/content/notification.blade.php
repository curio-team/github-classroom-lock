@props([
    'type' => 'info',
])
<div x-data {{ $attributes->class([
    'shadow rounded bg-blue-50 border-l-4 border-blue-400 p-4 mt-4',
    'bg-green-50 border-l-4 border-green-400 p-4' => $type === 'success',
    'bg-yellow-50 border-l-4 border-yellow-400 p-4' => $type === 'warning',
    'bg-red-50 border-l-4 border-red-400 p-4' => $type === 'error',
]) }}>
    <div class="flex justify-between">
        <div class="ml-3">
            <p class="text-sm leading-5">
                {{ $slot }}
            </p>
        </div>
        <div class="flex-shrink-0">
            <x-button.close @click="$root.remove()" />
        </div>
    </div>
</div>
