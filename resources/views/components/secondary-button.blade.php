<button {{ $attributes->merge(['type' => 'button', 'class' => 'button button-secondary']) }}>
    {{ $slot }}
</button>
