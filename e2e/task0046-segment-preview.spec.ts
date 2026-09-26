import { expect, test } from '@playwright/test';

test('segment preview and version mutation require authenticated tenant authority', async ({ request }) => {
    const workspace = '00000000-0000-4000-8000-000000000046';
    const operator = await request.get(`/workspaces/${workspace}/segments`, {
        headers: { Accept: 'application/json' },
    });
    expect(operator.status()).toBe(401);
    for (const path of [
        `/workspaces/${workspace}/segments/preview`,
        `/workspaces/${workspace}/segments/00000000-0000-4000-8000-000000000047/versions`,
        `/workspaces/${workspace}/segments/00000000-0000-4000-8000-000000000047/publish`,
    ]) {
        const response = await request.post(path, {
            headers: { Accept: 'application/json' },
            data: { definition: { schema_version: 1 }, confirmed: true, version: 1 },
        });
        // Web CSRF rejection runs before the authentication middleware on a bare POST.
        expect(response.status()).toBe(419);
        const body = await response.text();
        expect(body).not.toContain('definition_hash');
        expect(body).not.toContain('preview_members');
    }
});
