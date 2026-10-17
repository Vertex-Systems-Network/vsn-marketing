import { defineConfig, devices } from '@playwright/test';

// TASK-0104 AC-3: Next.js web app E2E against the Laravel /api/v1 backend.
export default defineConfig({
    testDir: './e2e-web',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: process.env.CI ? 'line' : 'list',
    use: {
        baseURL: 'http://127.0.0.1:3000',
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
            },
        },
    ],
    webServer: [
        {
            command: 'php artisan serve --host=127.0.0.1 --port=8000',
            url: 'http://127.0.0.1:8000/up',
            reuseExistingServer: !process.env.CI,
            timeout: 120_000,
        },
        {
            command: 'npm run start --workspace @vsn/web -- --hostname 127.0.0.1',
            url: 'http://127.0.0.1:3000/login',
            reuseExistingServer: !process.env.CI,
            timeout: 120_000,
            env: { VSN_API_ORIGIN: 'http://127.0.0.1:8000', NEXT_TELEMETRY_DISABLED: '1', VSN_INSECURE_COOKIES: '1' },
        },
    ],
});
