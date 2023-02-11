<div {{
    $attributes->merge([
        'class' => 'flex flex-col gap-2 p-4 sm:p-8 bg-white shadow sm:rounded-lg text-gray-900'
    ])
}}>
    {{ $slot }}
</div>
