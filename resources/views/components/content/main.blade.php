<div {{
    $attributes->merge([
        'class' => 'py-4 sm:py-8'
])
}}>
    <div class="flex flex-col gap-8 max-w-7xl mx-auto">
        {{ $slot }}
    </div>
</div>
