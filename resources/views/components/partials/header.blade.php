<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="manifest" href="/site.webmanifest">
        <link rel="mask-icon" href="/safari-pinned-tab.svg" color="#0A0A11">
        <meta name="msapplication-TileColor" content="#0A0A11">
        <meta name="theme-color" content="#0A0A11">

        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name') }} | Curio</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/dist/tippy.css" />

        {{ $slot }}
    </head>

    <body {{ $attributes->class(['min-h-screen antialiased', 'bg-surface text-ink' => ! $attributes->has('class')]) }}>
