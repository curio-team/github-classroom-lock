{{-- close button --}}
<a {{
   $attributes->merge([
    'class' => 'cursor-pointer text-gray-400 hover:text-gray-500 transition duration-150 ease-in-out',
    ])}}>
    <svg class="h-6 w-6 text-gray-400 hover:text-gray-500"
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
