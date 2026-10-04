import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test('authorized operator simulates a draft, confirms immutable publishing, and activates the pinned version', async ({ page }) => {
    const fixture = JSON.parse(execFileSync('php', ['e2e/seed-journey-operator.php'], { encoding: 'utf8' })) as {
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
    await page.goto(`/workspaces/${fixture.workspace}/journeys`);

    await expect(page.getByRole('heading', { name: 'Journey builder and timeline' })).toBeVisible();
    await page.getByLabel('Journey name').fill('Welcome journey');
    await page.getByRole('button', { name: 'Create draft' }).click();
    await expect(page.getByRole('status')).toContainText('Journey state: created');
    await expect(page.getByText('No executions yet. Draft simulation does not create execution records.')).toBeVisible();

    await page.getByLabel('Add node').selectOption('wait');
    await expect(page.getByLabel('Node 2 ID')).toBeFocused();
    await page.getByLabel('Transition 1 to').selectOption('wait-3');
    await page.getByRole('button', { name: 'Add transition' }).click();
    await page.getByLabel('Transition 2 from').selectOption('wait-3');
    await page.getByLabel('Transition 2 to').selectOption('finish');
    await expect(page.getByText(/Wait “wait-3”: 60 seconds/)).toBeVisible();
    await expect(page.getByText('No executions yet. Draft simulation does not create execution records.')).toBeVisible();

    await page.getByLabel('Add node').selectOption('action');
    await page.getByLabel('Transition 2 from').selectOption('wait-3');
    await page.getByLabel('Transition 2 to').selectOption('action-4');
    await page.getByRole('button', { name: 'Add transition' }).click();
    await page.getByLabel('Transition 3 from').selectOption('action-4');
    await page.getByLabel('Transition 3 to').selectOption('finish');
    await expect(page.getByText(/Action “action-4” is blocked in simulation/)).toBeVisible();

    await page.getByRole('button', { name: /Save draft/ }).click();
    await expect(page.getByRole('status')).toContainText('Journey state: saved');
    await page.getByRole('button', { name: 'Review and publish' }).focus();
    await page.getByRole('button', { name: 'Review and publish' }).click();
    const cancel = page.getByRole('button', { name: 'Go back' });
    await expect(page.getByRole('dialog', { name: 'Confirm publish' })).toBeVisible();
    await expect(cancel).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Review and publish' })).toBeFocused();

    await page.getByRole('button', { name: 'Review and publish' }).click();
    await page.getByRole('button', { name: 'Confirm publish' }).click();
    await expect(page.getByRole('status')).toContainText('Journey state: published');
    await expect(page.getByText(/Publish creates an immutable version/)).toHaveCount(0);
    await page.getByRole('button', { name: 'activate' }).click();
    await expect(page.getByRole('dialog', { name: 'Confirm activate' })).toContainText('lifecycle revision 1');
    await page.getByRole('button', { name: 'Confirm activate' }).click();
    await expect(page.getByRole('status')).toContainText('Journey state: active');
    await expect(page.getByText('No executions yet. Draft simulation does not create execution records.')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
});

test('unauthenticated users cannot read workspace journeys or execution history', async ({ request }) => {
    const response = await request.get('/workspaces/00000000-0000-4000-8000-000000000052/journeys', {
        headers: { Accept: 'application/json', 'X-Correlation-ID': 'task0052-journey-unauthenticated' },
    });

    expect(response.status()).toBe(401);
    const body = await response.text();
    expect(body).not.toContain('transition_history');
    expect(body).not.toContain('journey_execution_transitions');
    expect(body).not.toContain('provider_connection_id');
});
