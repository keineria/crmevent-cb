<!DOCTYPE html>
@php
    $themeUser = auth()->user();
    $theme = in_array($themeUser?->theme, \App\Support\Themes::THEMES, true) ? $themeUser->theme : null;
    $appearance = in_array($themeUser?->appearance, \App\Support\Themes::APPEARANCES, true) ? $themeUser->appearance : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme ?? \App\Support\Themes::DEFAULT }}" @class(['dark' => $appearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <meta name="robots" content="noindex, nofollow">

        {{-- Applies the colour theme before the page paints. Signed-in users get their account's choice; guests the last one used on this device. --}}
        <script>
            (function () {
                var account = { theme: @json($theme), appearance: @json($appearance) };
                var store = {};
                try { store = window.localStorage; } catch (e) {}
                if (account.theme) { try { store.setItem('theme', account.theme); } catch (e) {} }
                if (account.appearance) { try { store.setItem('appearance', account.appearance); } catch (e) {} }
                var theme = account.theme || (store.getItem && store.getItem('theme')) || '{{ \App\Support\Themes::DEFAULT }}';
                var appearance = account.appearance || (store.getItem && store.getItem('appearance')) || 'system';
                var dark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', theme);
                document.documentElement.classList.toggle('dark', dark);
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
