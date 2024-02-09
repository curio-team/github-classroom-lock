<x-app-layout>
    <x-notice>
        In order to use CurioGPT, you need to be signed in.
    </x-notice>

    <div class="flex flex-col items-center justify-center">
        <a href="{{ route('dashboard.student') }}"
            class="inline-flex items-center px-8 py-4 border border-transparent text-lg leading-6 font-medium rounded-md text-white bg-emerald-500 hover:bg-emerald-400 focus:outline-none focus:border-emerald-700 focus:shadow-outline-indigo active:bg-emerald-700 transition ease-in-out duration-150">
            Ask CurioGPT a question
        </a>
    </div>
</x-app-layout>
