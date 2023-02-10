<x-partials.header></x-partials.header>

<div id="app" class="text-black">
    @include('layouts.nav')

    <main class="max-w-prose mx-auto py-4">
        {{ $slot }}
    </main>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        @if($errors->any())
            @foreach($errors->all() as $error)
                window.Notyf.error(@js($error));
            @endforeach
        @endif

        @if(session()->has('success'))
            window.Notyf.success(@js(session()->get('success')));
        @endif

        @if(session()->has('debug'))
            console.log('Request returned the following debug information:')
            console.log(@js(session()->get('debug')));
        @endif
    });
</script>

<x-partials.footer />
