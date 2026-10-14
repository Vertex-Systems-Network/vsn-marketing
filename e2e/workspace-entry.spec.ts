import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test('keyboard sign in reaches only the current workspace and signs out', async ({ page }) => {
    const fixture = JSON.parse(execFileSync('php', ['e2e/seed-analytics-operator.php'], { encoding: 'utf8' }));
    await page.goto('/auth/login');
    await expect(page.getByRole('heading', { name: 'Sign in to VSN Marketing' })).toBeVisible();
    await page.getByLabel('Email').focus();
    await page.keyboard.type(fixture.email);
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Password')).toBeFocused();
    await page.keyboard.type('wrong-password');
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page.getByRole('alert')).toContainText('Sign in failed');
    await page.getByLabel('Password').fill(fixture.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/workspaces$/);
    await expect(page.getByRole('heading', { name: 'Your workspaces' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Analytics' })).toHaveAttribute('href',
        new RegExp(`/workspaces/${fixture.workspace}/analytics$`));
    await expect(page.getByRole('link', { name: 'Publishing' })).toHaveCount(0);
    await page.getByRole('button', { name: 'Sign out' }).click();
    await expect(page).toHaveURL(/\/auth\/login$/);
    const after = await page.request.get('/workspaces', { headers: { Accept: 'application/json' } });
    expect(after.status()).toBe(401);
});
