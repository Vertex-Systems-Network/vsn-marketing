import { expect, test } from '@playwright/test';

test.describe('TASK-0041 publishing operator guardrails', () => {
    test('does not expose operator or provider evidence without authenticated tenant authority', async ({ request }) => {
        const response = await request.get('/workspaces/00000000-0000-4000-8000-000000000041/publishing', {
            headers: {
                Accept: 'application/json',
                'X-Correlation-ID': 'task0041-operator-unauthenticated',
            },
        });

        expect(response.status()).toBe(401);

        const body = await response.text();
        expect(body).not.toContain('canonical_provider_evidence');
        expect(body).not.toContain('provider_connection_id');
        expect(body).not.toContain('capability_evidence_id');
        expect(body).not.toContain('secret_reference');
        expect(body).not.toContain('access_token');
    });
});
