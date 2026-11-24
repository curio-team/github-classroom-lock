<nav x-data="{ openSidebar: false }" class="bg-ink text-white py-4 px-4 sm:px-8">
    <x-content.stack-layout row class="items-center justify-between max-w-6xl mx-auto">
        <a class="shrink-0"
           href="{{ route('dashboard') }}">
            <x-application-logo />
        </a>

        <button class="block md:hidden text-white"
                type="button"
                @click="openSidebar = true"
                aria-label="{{ __('Toggle navigation') }}">
            <x-icons.menu width="32px"
                          height="32px" />
        </button>

        <div class="md:flex! bg-ink bottom-0 top-0 right-0 z-40 flex grow fixed md:static p-4 md:p-0 shadow-card-hover md:shadow-none min-w-[50%] gap-4 flex-col md:flex-row"
            :class="{ 'hidden': !openSidebar }">
            <button class="block md:hidden self-end text-white"
                    type="button"
                    @click="openSidebar = false"
                    aria-label="{{ __('Toggle navigation') }}">
                <x-icons.close width="32px"
                               height="32px"/>
            </button>

            <!-- Left Side Of Navbar -->
            <div class="flex flex-col md:flex-row md:items-center gap-4 md:gap-6 md:grow md:pl-6">
                @auth
                    @if (user()->isTeacher())
                        <x-nav-link href="{{ route('exams.index') }}" :active="request()->routeIs('exams.*')">Examens</x-nav-link>
                        <x-nav-link href="{{ route('audit-log') }}" :active="request()->routeIs('audit-log')">Auditlog</x-nav-link>
                        <x-nav-link href="{{ route('dashboard.teacher') }}" :active="request()->routeIs('dashboard.teacher')" class="opacity-60">Oude Classroom-teams</x-nav-link>
                    @endif
                @endauth
            </div>

            <!-- Right Side Of Navbar -->
            <div class="flex flex-col md:flex-row md:items-center gap-4 md:gap-6">
                @guest
                    <x-nav-link href="{{ route('login') }}">{{ __('Inloggen') }}</x-nav-link>
                @else
                    <x-nav-link
                        target="_blank"
                        href="{{ url('https://login.curio.codes') }}">
                        {{ Auth::user()->name }}
                    </x-nav-link>

                    <form action="{{ route('logout') }}"
                        method="POST">
                        @csrf
                        <x-buttons.primary submit>
                            {{ __('Log uit') }}
                        </x-buttons.primary>
                    </form>
                @endguest
            </div>
        </div>
    </x-content.stack-layout>
</nav>
