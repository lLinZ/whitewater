import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { HeroUIProvider } from '@heroui/react';
import { registerServiceWorker } from '@/lib/push';
import { applyTheme } from '@/lib/theme';

const appName = import.meta.env.VITE_APP_NAME || 'Whitewater';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        // El tema del miembro, antes de montar: el script del <head> ya pintó
        // el guardado, esto corrige si cambió desde otro dispositivo.
        const user = props.initialPage.props.auth?.user;
        applyTheme(user?.color, user?.theme);

        const root = createRoot(el);

        root.render(
            <HeroUIProvider>
                <App {...props} />
            </HeroUIProvider>,
        );
    },
    progress: {
        // La barra de carga sigue al acento elegido.
        color: 'var(--app-accent, #7c3aed)',
        showSpinner: false,
    },
});

// Registra el service worker (necesario para Web Push y modo PWA).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        registerServiceWorker();
    });
}
