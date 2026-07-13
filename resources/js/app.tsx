import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { HeroUIProvider } from '@heroui/react';
import { registerServiceWorker } from '@/lib/push';

const appName = import.meta.env.VITE_APP_NAME || 'Whitewater';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <HeroUIProvider>
                <App {...props} />
            </HeroUIProvider>,
        );
    },
    progress: {
        color: '#7c3aed',
        showSpinner: false,
    },
});

// Registra el service worker (necesario para Web Push y modo PWA).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        registerServiceWorker();
    });
}
