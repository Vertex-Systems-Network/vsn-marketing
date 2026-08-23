import inertia from '@inertiajs/vite';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.tsx'], refresh: true }),
        inertia(),
        react(),
        tailwindcss(),
    ],
    server: {
        host: '0.0.0.0',
        watch: { ignored: ['**/.ai/**', '**/vendor/**'] },
    },
});
