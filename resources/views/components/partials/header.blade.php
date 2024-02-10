<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Software Developer | Curio</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/dist/tippy.css" />

        {{ $slot }}
    </head>

    <body class="min-h-full">
