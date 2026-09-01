<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="light">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, maximum-scale=1">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="theme-color" content="#F5F5F7">
        <meta name="apple-mobile-web-app-title" content="Whitewater">

        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
        <link rel="icon" type="image/png" href="/icons/icon-192.png">

        <title inertia>{{ config('app.name', 'Whitewater') }}</title>

        {{-- Pinta el tema guardado antes del primer frame. Sin esto la app
             abre en claro/violeta y salta al tema del miembro cuando React
             monta: un parpadeo muy visible al abrirla desde el ícono. --}}
        <script>
            (function () {
                try {
                    var saved = JSON.parse(localStorage.getItem('whitewater.theme') || 'null');
                    if (!saved) return;

                    var dark = saved.mode === 'dark' || (saved.mode === 'system'
                        && window.matchMedia('(prefers-color-scheme: dark)').matches);

                    var root = document.documentElement;
                    root.classList.toggle('dark', dark);
                    root.classList.toggle('light', !dark);
                    root.style.colorScheme = dark ? 'dark' : 'light';

                    var vars = dark ? saved.dark : saved.light;
                    for (var name in vars) root.style.setProperty(name, vars[name]);

                    var meta = document.querySelector('meta[name="theme-color"]');
                    if (meta) meta.setAttribute('content', dark ? '#0B0B0F' : '#F5F5F7');
                } catch (e) {
                    // Tema por defecto: no vale la pena romper la carga por esto.
                }
            })();
        </script>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/Pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-background text-foreground">
        @inertia
    </body>
</html>
