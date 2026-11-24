@php
    [$label, $class] = match ($status) {
        'valid' => ['Actief', 'badge-success'],
        'expired' => ['Verlopen', 'badge-warning'],
        'revoked' => ['Ingetrokken', 'badge-danger'],
        'offboarded' => ['Afgesloten', 'badge-secondary'],
        default => ['Geen link', 'badge-secondary'],
    };
@endphp
<span class="badge {{ $class }}">{{ $label }}</span>
