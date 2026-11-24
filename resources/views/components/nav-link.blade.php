@props(['active' => false])

<a {{ $attributes->class([
    'font-bold no-underline transition duration-150 ease-in-out hover:text-white',
    'text-white' => $active,
    'text-white/75' => ! $active,
]) }}>
    {{ $slot }}
</a>
