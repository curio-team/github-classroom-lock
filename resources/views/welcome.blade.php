<x-app-layout>
    <div class="rounded bg-slate-500 border border-slate-600 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-slate-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 2a8 8 0 100 16 8 8 0 000-16zM8 9a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1zm0 2a1 1 0 100 2h4a1 1 0 100-2H8z"
                        clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm leading-5 text-slate-200">
                    In order to use CurioGPT, you need to be in an active examination. Log in to your account to see if
                    you can access it.
                </p>
            </div>
        </div>
    </div>

    <div class="flex flex-col items-center justify-center">
        <a href="{{ route('dashboard.student') }}"
            class="inline-flex items-center px-8 py-4 border border-transparent text-lg leading-6 font-medium rounded-md text-white bg-emerald-500 hover:bg-emerald-400 focus:outline-none focus:border-emerald-700 focus:shadow-outline-indigo active:bg-emerald-700 transition ease-in-out duration-150">
            Ask CurioGPT a question
        </a>
    </div>
</x-app-layout>
