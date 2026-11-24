@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-success-ink']) }}>
        {{ $status }}
    </div>
@endif
