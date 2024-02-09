<x-content.section tight class="rounded !bg-slate-500 border border-slate-600 p-4">
    <div class="flex">
        <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-slate-400"
                    fill="currentColor"
                    viewBox="0 0 20 20">
                <path fill-rule="evenodd"
                        d="M10 2a8 8 0 100 16 8 8 0 000-16zM8 9a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1zm0 2a1 1 0 100 2h4a1 1 0 100-2H8z"
                        clip-rule="evenodd" />
            </svg>
        </div>
        <div class="ml-3">
            <p class="text-sm leading-5 text-slate-200">
                {{ $slot}}
            </p>
        </div>
    </div>
</x-content.section>
