import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

async function openSegmentBuilder(page: import('@playwright/test').Page, contacts = 1) {
    const fixture = JSON.parse(execFileSync('php', [
        'e2e/seed-segment-operator.php', `--contacts=${contacts}`,
    ], { encoding: 'utf8' })) as {
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

    return fixture;
}

test('authorized operator reviews states, previews, saves and publishes a pinned segment version', async ({ page }) => {
    await openSegmentBuilder(page);
    await expect(page.getByRole('heading', { name: 'Segment builder' })).toBeVisible();
    await expect(page.getByText('No saved segments in this workspace.')).toBeVisible();
    await expect(page.getByText(/no approved AI route is configured/i)).toBeVisible();
    await page.getByLabel('Structured definition (JSON)').fill('{');
    await expect(page.getByRole('alert')).toContainText('valid JSON object');
    await page.getByRole('button', { name: 'Start visual rule builder' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('group', { name: 'Audience rules' })).toBeVisible();
    await page.getByRole('button', { name: 'Add nested group' }).click();
    await expect(page.getByRole('group', { name: 'Nested rule group' })).toBeVisible();
    await page.getByRole('button', { name: 'Add exclusion (NOT)' }).first().click();
    await expect(page.getByText('Exclude contacts matching:')).toBeVisible();
    await page.getByRole('button', { name: 'Remove exclusion' }).click();
    const definition = await page.getByLabel('Structured definition (JSON)').inputValue();
    await page.route('**/segments/preview', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, 300));
        await route.continue();
    }, { times: 1 });
    const previewResponse = page.waitForResponse((response) =>
        response.request().method() === 'POST' && response.url().endsWith('/segments/preview'));
    await page.getByRole('button', { name: 'Preview bounded count' }).click();
    await expect(page.getByRole('button', { name: 'Evaluating…' })).toBeDisabled();
    const response = await previewResponse;
    expect(response.status()).toBe(200);
    const previewPage = await response.json() as {
        component: string;
        props: { preview_result: { count_kind: string; count: number | null } | null };
    };
    expect(previewPage.component).toBe('segmentation/operator');
    expect(previewPage.props.preview_result).toMatchObject({ count_kind: 'exact', count: 1 });
    await expect(page.getByText('1 contacts · exact at evaluation time')).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/Source freshness is unknown/)).toBeVisible();
    await expect(page.getByText(/Member identities and personal details are hidden/)).toBeVisible();
    await page.getByLabel('Structured definition (JSON)').fill(`${definition}\n`);
    await expect(page.getByText(/This count is stale; preview again/)).toBeVisible();
    await page.getByLabel('Structured definition (JSON)').fill(JSON.stringify({
        schema_version: 1, subject: 'contact', root: { type: 'group', operator: 'all', children: [
            { type: 'attribute', field: 'company.domain', operator: 'equals', value: 'absent.example.test' },
        ] },
    }, null, 2));
    await page.getByRole('button', { name: 'Preview bounded count' }).click();
    await expect(page.getByText('0 contacts · exact at evaluation time')).toBeVisible();
    await page.getByLabel('Structured definition (JSON)').fill(definition);
    await page.getByRole('button', { name: 'Preview bounded count' }).click();
    await expect(page.getByText('1 contacts · exact at evaluation time')).toBeVisible();
    await page.getByLabel('Segment name').fill('E2E pinned segment');
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Confirm and save draft' }).click();
    await expect(page.getByRole('heading', { name: 'Draft version saved' })).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Publish selected immutable version' }).click();
    await expect(page.getByRole('heading', { name: 'Version published' })).toBeVisible();
    await expect(page.getByText(/published 1/).first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
});

test('large audiences are capped without materializing member identities', async ({ page }) => {
    await openSegmentBuilder(page, 251);
    await page.getByRole('button', { name: 'Start visual rule builder' }).click();
    await page.getByRole('button', { name: 'Preview bounded count' }).click();
    await expect(page.getByText(/At least 251 contacts/)).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/Member identities and personal details are hidden/)).toBeVisible();
});
