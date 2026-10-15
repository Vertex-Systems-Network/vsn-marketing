import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test('audit operator reviews two pages on mobile without seeing evidence or another workspace', async ({ page }) => {
    const fixture = JSON.parse(execFileSync('php', ['e2e/seed-enterprise-audit-operator.php'], { encoding: 'utf8' })) as {
        workspace: string; foreign_workspace: string; email: string; password: string;
    };
    await page.goto('/');
    const xsrf = (await page.context().cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
    expect(xsrf).toBeDefined();
    const login = await page.request.post('/auth/login', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value) },
        data: { email: fixture.email, password: fixture.password },
    });
    expect(login.status()).toBe(204);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/workspaces/${fixture.workspace}/enterprise`);
    await expect(page.getByRole('heading', { name: 'Enterprise controls' })).toBeVisible();
    await page.getByRole('button', { name: 'Review audit metadata' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('list', { name: 'Audit events' }).getByRole('listitem')).toHaveCount(25);
    await page.getByRole('button', { name: 'Load older events' }).click();
    await expect(page.getByRole('list', { name: 'Audit events' }).getByRole('listitem')).toHaveCount(26);
    await expect(page.getByText('never-show-evidence')).toHaveCount(0);
    await expect(page.getByText('e2e.audit.foreign')).toHaveCount(0);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    const foreign = await page.request.get(`/workspaces/${fixture.foreign_workspace}/audit/export`, {
        headers: { Accept: 'application/json' },
    });
    expect(foreign.status()).toBe(403);
});
