{{-- close button --}}
<a {{
   $attributes->merge([
    'class' => 'cursor-pointer text-muted hover:text-ink transition duration-150 ease-in-out',
    ])}}>
    <svg class="h-6 w-6 text-muted hover:text-ink"
         fill="none"
         stroke="currentColor"
         viewBox="0 0 24 24"
         xmlns="http://www.w3.org/2000/svg">
        <path stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M6 18L18 6M6 6l12 12"></path>
    </svg>
</a>
