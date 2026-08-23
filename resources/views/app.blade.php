<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'VSN Marketing') }}</title>
        </x-inertia::head>
    </head>
    <body class="min-h-screen bg-neutral-950 text-neutral-100 antialiased">
        <x-inertia::app />
    </body>
</html>
