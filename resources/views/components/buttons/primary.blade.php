@props([
    'big',
    'submit'
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
        'flex items-center justify-center px-4 py-2 gap-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest ring-gray-300 transition ease-in-out duration-150 aria-disabled:cursor-not-allowed aria-disabled:opacity-25',
        'px-8 py-4' => isset($big),
        'hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring focus:ring-offset-2' => !isset($attributes['aria-disabled']),
        'flex-shrink-0'
    ]) }}>
    @isset($icon)
        {{ $icon }}
    @endisset
    {{ $slot }}
</a>
