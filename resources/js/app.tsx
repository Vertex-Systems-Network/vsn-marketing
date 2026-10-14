import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'VSN Marketing';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>([
            './pages/**/*.tsx', '!./pages/**/*.test.tsx',
        ]);
        const page = await pages[`./pages/${name}.tsx`]?.();
        if (!page) throw new Error(`Page not found: ${name}`);
        return page.default;
    },
    progress: {
        color: '#737373',
    },
});
