@props([
    'big',
    'tight',
    'submit',
    'variant' => 'primary',
])
@php
    $newOnClick = isset($onclick) ? $onclick : '';

    if(isset($submit))
        $newOnClick = 'this.closest("form").submit()';
@endphp

@isset($modal)
    @php
        if(!isset($attributes['modal-id']))
            throw new Exception('Buttons with Modals must provide a modal-id attribute to the button.');

        $modalId = $attributes['modal-id'];
        $newOnClick = "document.dispatchEvent(new CustomEvent('modal-should-open',  { detail: { modalId: '$modalId' } }))";
    @endphp
    <script>
        document.addEventListener('modal-should-open', function (event) {
            if (event.detail.modalId === '{{ $modalId }}') {
                let modal = document.getElementById('{{ $modalId }}');

                if (!modal) {
                    const template = document.getElementById('template-{{ $modalId }}').content.cloneNode(true);
                    modal = template.firstElementChild;
                    modal.id = '{{ $modalId }}';
                    document.body.appendChild(modal);
                } else {
                    modal._x_dataStack[0].showModal = true;
                }
            }
        });
    </script>
    <template id="template-{{ $modalId }}">
        {{ $modal }}
    </template>
@endisset

<a {{ $attributes->merge([
    'href' => (isset($submit) || isset($modal) || isset($attributes['aria-disabled']) || isset($attributes['wire:click']) || isset($attributes['wire:click.stop']) || isset($attributes['wire:click.prevent']) || isset($attributes['onclick']) || isset($attributes['@click'])) ? 'javascript:void(0)' : '#',
    'onclick' => $newOnClick,
])->class([
        'button',
        'button-' . $variant => $variant !== 'primary',
        'button-big' => isset($big),
        'button-tight' => isset($tight),
    ]) }}>
    @isset($icon)
        {{ $icon }}
    @endisset
    {{ $slot }}
</a>
