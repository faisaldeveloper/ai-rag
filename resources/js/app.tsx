import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Knowledge Assistant';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>(['./pages/**/*.tsx', '!./pages/**/*.test.tsx']);
        const page = pages[`./pages/${name}.tsx`];

        if (page === undefined) {
            throw new Error(`Page not found: ${name}`);
        }

        return page().then((module) => module.default);
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#1d4ed8' },
});
