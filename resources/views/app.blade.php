<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <link rel="icon" href="/images/favicon-32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/images/favicon-48.png" type="image/png" sizes="48x48">
        <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">
        <meta name="theme-color" content="#00435C">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        <x-inertia::head>
            <title>{{ config('app.name') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
