import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        include: ['resources/js/**/*.test.{ts,tsx}', 'packages/*/tests/**/*.test.ts'],
    },
});
