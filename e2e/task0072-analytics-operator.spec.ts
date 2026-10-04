import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test('mobile operator reads scoped evidence, generates a report and disables their daily schedule', async ({ page }) => {
    const f = JSON.parse(execFileSync('php', ['e2e/seed-analytics-operator.php'], { encoding: 'utf8' }));
    await page.context().setExtraHTTPHeaders({ 'X-Brand-Id': f.brand });
    await page.goto('/');
    const xsrf = (await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN');
    expect(xsrf).toBeDefined();
    const login = await page.request.post('/auth/login', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value) },
        data: { email: f.email, password: f.password },
    });
    expect(login.status()).toBe(204);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/workspaces/${f.workspace}/analytics`);
    await expect(page.getByRole('heading', { name: 'Analytics reports', exact: true })).toBeVisible();
    await expect(page.getByRole('rowheader', { name: 'count', exact: true })).toBeVisible();
    await expect(page.getByText(/Source completeness: unknown/)).toBeVisible();
    await page.getByText('Definition and evidence fingerprint', { exact: true }).click();
    await expect(page.getByText(/Definition: [a-f0-9]{64}/)).toBeVisible();
    await page.getByLabel('Start date (UTC, inclusive)').fill('2026-10-02');
    await page.getByLabel('End date (UTC, exclusive)').fill('2026-10-03');
    await page.getByRole('button', { name: 'Generate report', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Immutable report created.' })).toBeVisible();
    await page.getByLabel('Canonical source', { exact: true }).fill('fixture');
    await page.getByRole('button', { name: 'Check source quality', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByText('Immutable source quality check created.', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'product.viewed · unknown', exact: true })).toBeVisible();
    await expect(page.getByText('Expected source total', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Schedule daily UTC report' }).click();
    await expect(page.getByText('Daily UTC schedule created. Reports stay inside this workspace.')).toBeVisible();
    await page.getByRole('button', { name: 'Disable schedule' }).click();
    await expect(page.getByText('Schedule status: owner_disabled')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Request validated explanation' })).toBeDisabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
});

test('anonymous analytics requests cannot expose report evidence', async ({ request }) => {
    const r = await request.get('/workspaces/00000000-0000-4000-8000-000000000072/analytics', {
        headers: { Accept: 'application/json' },
    });
    expect(r.status()).toBe(401);
    expect(await r.text()).not.toContain('receipt_cutoff_utc');
});
