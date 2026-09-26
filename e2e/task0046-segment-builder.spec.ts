import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

type ManifestEntry = { file: string };
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8')) as Record<string, ManifestEntry>;

test('operator can build nested rules with keyboard and sees bounded failure states on mobile', async ({ page }) => {
    const props = {
        proposal_available: false,
        fields: [{ id: 'contact.created_at', type: 'timestamp', operators: ['after', 'is_set'] }],
        registered_events: ['email.opened'], segments: [], proposal_result: null,
        saved_segment: null,
        preview_result: {
            status: 'timeout_or_unavailable', count_kind: 'unavailable', count: null,
            definition_hash: 'a'.repeat(64), definition_version: null,
            evaluated_at: '2026-09-26 12:00:00 UTC', source_freshness_at: null,
            eligibility_explanation: 'Delivery checks consent and suppression at send admission.',
        },
        actions: { propose: '/proposals', store: '/versions', preview: '/preview', revise_base: '/segments' },
    };
    const dataPage = JSON.stringify({ component: 'segmentation/operator', props,
        url: '/e2e-segment-builder', version: 'e2e' }).replaceAll('&', '&amp;').replaceAll("'", '&#39;');
    await page.route('**/e2e-segment-builder', (route) => route.fulfill({
        status: 200, contentType: 'text/html',
        body: `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>
            <body><div id="app" data-page='${dataPage}'></div>
            <script type="module" src="/build/${manifest['resources/js/app.tsx'].file}"></script></body></html>`,
    }));
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/e2e-segment-builder');
    await expect(page.getByRole('heading', { name: 'Segment builder' })).toBeVisible();
    await expect(page.getByText(/Count unavailable/)).toBeVisible();
    await expect(page.getByText(/Source freshness is unknown/)).toBeVisible();
    await page.getByRole('button', { name: 'Start visual rule builder' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('group', { name: 'Audience rules' })).toBeVisible();
    await page.getByRole('button', { name: 'Add nested group' }).click();
    await expect(page.getByRole('group', { name: 'Nested rule group' })).toBeVisible();
    await page.getByRole('button', { name: 'Add exclusion (NOT)' }).first().click();
    await expect(page.getByText('Exclude contacts matching:')).toBeVisible();
});
