<a {{
    $attributes->merge([
        'class' => 'text-sm font-bold text-primary hover:text-primary-dark rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40'
    ]);
}}>
    {{ $slot }}
</a>
