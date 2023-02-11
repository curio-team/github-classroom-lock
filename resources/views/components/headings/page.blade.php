@props([
    'center' => false
])
<div class="flex flex-col gap-2 flex-wrap items-stretch justify-between">
    <div class="flex flex-col sm:flex-row gap-2 flex-wrap items-stretch sm:items-center @if ($center) justify-center @else justify-between @endif">
        <h2 {{
            $attributes->merge([
                'class' => 'font-semibold text-xl leading-tight text-center sm:text-left'
            ])
        }}>
            <div class="flex flex-row gap-2 items-center">
                @isset($icon)
                    <img src="{{ $icon }}"
                        alt="{{ $slot }} icon"
                        class="h-8 w-8 -my-8 rounded object-cover" />
                @endisset

                {{ $slot }}
            </div>
        </h2>
        @isset($mainAction)
            {{ $mainAction }}
        @endisset
    </div>
    @isset($actions)
        {{ $actions }}
    @endisset
</div>
