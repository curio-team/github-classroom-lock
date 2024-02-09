<nav x-data="{ openSidebar: false }" class="bg-white shadow-sm py-4 px-8">
    <x-content.stack-layout row class="items-center justify-between max-w-4xl mx-auto">
        <a class="max-w-[100px] grow"
           href="{{ route('dashboard') }}">
            <x-logos.light-logo />
        </a>

        <button class="block md:hidden"
                type="button"
                @click="openSidebar = true"
                aria-label="{{ __('Toggle navigation') }}">
            <x-icons.menu width="32px"
                          height="32px" />
        </button>

        <div class="md:!flex bg-white bottom-0 top-0 right-0 flex grow fixed md:static p-4 md:p-0 shadow-md md:shadow-none min-w-[50%] gap-4 flex-col md:flex-row"
            :class="{ 'hidden': !openSidebar }">
            <button class="block md:hidden self-end"
                    type="button"
                    @click="openSidebar = false"
                    aria-label="{{ __('Toggle navigation') }}">
                <x-icons.close width="32px"
                               height="32px"/>
            </button>

            <!-- Left Side Of Navbar -->
            <x-content.stack-layout row class="md:grow">
                @auth
                <x-buttons.link
                    href="{{ route('dashboard.student') }}">
                    CurioGPT
                </x-buttons.link>

                @if (user()->isTeacher())
                    <x-buttons.link
                        href="{{ route('dashboard.teacher') }}">
                        Docent Dashboard
                    </x-buttons.link>
                @endif
                @endauth
            </x-content.stack-layout>

            <!-- Right Side Of Navbar -->
            <x-content.stack-layout row class="items-center">
                @guest
                    <x-buttons.link href="{{ route('login') }}">{{ __('Inloggen') }}</x-buttons.link>
                @else
                    <x-buttons.link
                        target="_blank"
                        href="{{ url('https://login.curio.codes') }}">
                        {{ Auth::user()->name }} <span class="caret"></span>
                    </x-buttons.link>

                    <form action="{{ route('logout') }}"
                        method="POST">
                        @csrf
                        <x-buttons.primary submit>
                            {{ __('Log uit') }}
                        </x-buttons.primary>
                    </form>
                @endguest
            </x-content.stack-layout>
        </div>
    </x-content.stack-layout>
</nav>
