import { expect, test } from '@playwright/test';

test('critical phase-one surfaces are healthy', async ({ page, request }) => {
    await page.goto('/');
    await expect(
        page.getByRole('heading', {
            name: 'Provider-agnostic marketing infrastructure, built on deterministic guardrails.',
        }),
    ).toBeVisible();

    const health = await request.get('/api/health/ready', {
        headers: { 'X-Correlation-ID': 'playwright-critical-smoke' },
    });
    expect(health.ok()).toBeTruthy();
    expect(health.headers()['x-correlation-id']).toBe('playwright-critical-smoke');
    expect(await health.json()).toMatchObject({
        status: 'ok',
        checks: { database: 'ok', cache: 'ok' },
        correlation_id: 'playwright-critical-smoke',
    });

    const metrics = await request.get('/api/metrics');
    expect(metrics.ok()).toBeTruthy();
    expect(await metrics.text()).toContain('vsn_http_requests_total');
});
