<x-app-layout>
    <x-notice>
        Om CurioGPT te gebruiken, moet je eerst inloggen.
    </x-notice>

    <div class="flex flex-col items-center justify-center mt-5">
        <a href="{{ route('dashboard.student') }}"
            class="inline-flex items-center px-8 py-4 border border-transparent text-lg leading-6 font-medium rounded-md text-white bg-emerald-500 hover:bg-emerald-400 focus:outline-none focus:border-emerald-700 focus:shadow-outline-indigo active:bg-emerald-700 transition ease-in-out duration-150">
            Prompt CurioGPT
        </a>
    </div>
</x-app-layout>
