import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test('authorized operator builds, previews, saves and publishes a pinned segment version', async ({ page }) => {
    const fixture = JSON.parse(execFileSync('php', ['e2e/seed-segment-operator.php'], { encoding: 'utf8' })) as {
        workspace: string; email: string; password: string;
    };
    await page.goto('/');
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(xsrf).toBeDefined();
    const login = await page.request.post('/auth/login', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value) },
        data: { email: fixture.email, password: fixture.password },
    });
    expect(login.status()).toBe(204);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/workspaces/${fixture.workspace}/segments`);
    await expect(page.getByRole('heading', { name: 'Segment builder' })).toBeVisible();
    await page.getByRole('button', { name: 'Start visual rule builder' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('group', { name: 'Audience rules' })).toBeVisible();
    await page.getByRole('button', { name: 'Add nested group' }).click();
    await expect(page.getByRole('group', { name: 'Nested rule group' })).toBeVisible();
    await page.getByRole('button', { name: 'Add exclusion (NOT)' }).first().click();
    await expect(page.getByText('Exclude contacts matching:')).toBeVisible();
    await page.getByRole('button', { name: 'Remove exclusion' }).click();
    await page.getByRole('button', { name: 'Preview bounded count' }).click();
    await expect(page.getByText('1 contacts · exact at evaluation time')).toBeVisible();
    await expect(page.getByText(/Source freshness is unknown/)).toBeVisible();
    await expect(page.getByText(/Member identities and personal details are hidden/)).toBeVisible();
    await page.getByLabel('Segment name').fill('E2E pinned segment');
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Confirm and save draft' }).click();
    await expect(page.getByRole('heading', { name: 'Draft version saved' })).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Publish selected immutable version' }).click();
    await expect(page.getByRole('heading', { name: 'Version published' })).toBeVisible();
    await expect(page.getByText(/published 1/)).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
});
