<!DOCTYPE html>
<html
        lang="{{ str_replace('_', '-', app()->getLocale()) }}"
        @class([
            'dark' => ($appearance ?? 'system') == 'dark',
            'high-contrast' => $accessibilityHighContrast ?? false,
            'reduce-motion' => $accessibilityReduceMotion ?? false,
        ])
    >
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

                document.documentElement.style.colorScheme =
                    document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            })();
        </script>

        {{-- FOUC: match the ink surfaces of qiick-theme.css (app-ui) before the CSS loads --}}
        <style>
            html {
                background-color: #ffffff;
            }

            html.dark {
                background-color: #0e171e;
            }
        </style>

        @if (! empty($projectFaviconUrl))
            <link rel="icon" href="{{ $projectFaviconUrl }}">
        @else
            <link rel="icon" href="/favicon.ico" sizes="48x48">
            <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @endif
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#0E171E">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ app(\App\Services\SettingsRepositoryService::class)->appName() }}</title>
        </x-inertia::head>
        {{-- Theme preset CSS variables (+ optional custom brand hex via --brand-primary).
             The id lets the Appearance live preview rewrite this block (resources/js/lib/theme-css.ts). --}}
        @php
            $themeCss = $projectThemeCss ?? null;
            $themeLight = is_array($themeCss['light'] ?? null) ? $themeCss['light'] : [];
            $themeDark = is_array($themeCss['dark'] ?? null) ? $themeCss['dark'] : [];
            $brandPrimary = $themeCss['brandPrimary'] ?? null;
            $brandPrimaryDark = $themeCss['brandPrimaryDark'] ?? $brandPrimary;
            $brandPrimaryForeground = $themeCss['brandPrimaryForeground'] ?? null;
            $brandPrimaryDarkForeground = $themeCss['brandPrimaryDarkForeground'] ?? $brandPrimaryForeground;
        @endphp
        @if ($themeLight !== [] || $themeDark !== [] || ! empty($brandPrimary))
            <style id="instance-theme-css">
                :root {
                    @foreach ($themeLight as $cssVar => $cssValue)
                        --{{ $cssVar }}: {{ $cssValue }};
                    @endforeach
                    @if (! empty($brandPrimary))
                        --brand-primary: {{ $brandPrimary }};
                        --brand-primary-foreground: {{ $brandPrimaryForeground ?? '#ffffff' }};
                        --brand-primary-dark: {{ $brandPrimaryDark ?? $brandPrimary }};
                        --brand-primary-dark-foreground: {{ $brandPrimaryDarkForeground ?? $brandPrimaryForeground ?? '#ffffff' }};
                    @endif
                }
                @if ($themeDark !== [])
                .dark {
                    @foreach ($themeDark as $cssVar => $cssValue)
                        --{{ $cssVar }}: {{ $cssValue }};
                    @endforeach
                }
                @endif
            </style>
        @endif
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
