@php
    [$label, $class] = match ($status) {
        'valid' => ['Actief', 'bg-green-100 text-green-800'],
        'expired' => ['Verlopen', 'bg-amber-100 text-amber-800'],
        'revoked' => ['Ingetrokken', 'bg-red-100 text-red-800'],
        'offboarded' => ['Afgesloten', 'bg-slate-200 text-slate-600'],
        default => ['Geen link', 'bg-gray-100 text-gray-600'],
    };
@endphp
<span class="inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $class }}">{{ $label }}</span>
