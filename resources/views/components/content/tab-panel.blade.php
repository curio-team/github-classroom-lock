<div {{
    $attributes->class([
        'flex flex-col'
    ])
}}>
    <div class="flex flex-wrap justify-center gap-x-2 pt-4 px-2 text-sm font-medium">
        {{ $tabs }}
    </div>

    <div class="flex flex-col gap-2 grow p-2 border border-ink/15 rounded-lg rounded-t-none">
        {{ $slot }}
    </div>
</div>
