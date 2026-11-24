<button {{ $attributes->merge(['type' => 'submit', 'class' => 'button button-danger']) }}>
    {{ $slot }}
</button>
