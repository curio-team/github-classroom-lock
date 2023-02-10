@props([
    'name',
    'label',
    'value',
    'type' => 'text',
    'min' => null,
    'max' => null,
    'step' => null,
])

@if($label ?? null)
    @include('components.inputs.partials.label')
@endif

<input
    type="{{ $type }}"
    id="{{ $name }}"
    name="{{ $name }}"
    value="{{ old($name, $value ?? '') }}"
    {{ ($required ?? false) ? 'required' : '' }}
    {{ $attributes->class([
        'p-2 border border-gray-300 rounded-md',
    ]) }}
    {{ $min ? "min={$min}" : '' }}
    {{ $max ? "max={$max}" : '' }}
    {{ $step ? "step={$step}" : '' }}
    autocomplete="off"
>

@error($name)
    @include('components.inputs.partials.error')
@enderror
