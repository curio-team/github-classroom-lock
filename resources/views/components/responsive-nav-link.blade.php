@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full pl-3 pr-4 py-2 border-l-4 border-primary text-left text-base font-bold text-white bg-white/5 focus:outline-none focus:bg-white/10 focus:border-primary-dark transition duration-150 ease-in-out'
            : 'block w-full pl-3 pr-4 py-2 border-l-4 border-transparent text-left text-base font-bold text-white/75 hover:text-white hover:bg-white/5 hover:border-white/30 focus:outline-none focus:text-white focus:bg-white/5 focus:border-white/30 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
