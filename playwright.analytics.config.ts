import { defineConfig } from '@playwright/test';
import base from './playwright.config';
export default defineConfig({
    ...base,
    testIgnore: [],
    testMatch: '**/task0072-analytics-operator.spec.ts',
    webServer: {
        command: 'php -S 127.0.0.1:8000 -t public e2e/analytics-router.php',
        url: 'http://127.0.0.1:8000/up',
        reuseExistingServer: false,
        timeout: 120_000,
    },
});
