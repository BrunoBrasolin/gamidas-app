<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Link preview of a public page, e.g. a shared tier list; kept out of search engines. --}}
        @isset($preview)
            <meta name="robots" content="noindex">
            <meta name="description" content="{{ $preview['description'] }}">
            <meta property="og:type" content="website">
            <meta property="og:title" content="{{ $preview['title'] }}">
            <meta property="og:description" content="{{ $preview['description'] }}">
            <meta property="og:url" content="{{ $preview['url'] }}">
            @if ($preview['image'])
                <meta property="og:image" content="{{ $preview['image'] }}">
                <meta name="twitter:card" content="summary_large_image">
            @endif
        @endisset

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ $preview['title'] ?? (str_starts_with($page['component'], 'game/') ? 'Jogo da Atenção' : config('app.name', 'Laravel')) }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
