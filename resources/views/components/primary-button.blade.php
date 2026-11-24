<button {{ $attributes->merge(['type' => 'submit', 'class' => 'button']) }}>
    {{ $slot }}
</button>
