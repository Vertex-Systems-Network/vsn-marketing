import { describe, expect, it, vi } from 'vitest';
import { ApiError, createClient } from '../src/generated';

function jsonResponse(status: number, body: unknown, contentType = 'application/json'): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': contentType } });
}

describe('generated API client (TASK-0103 AC-3)', () => {
    it('sends bearer token and tenant headers and builds query strings', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { data: [], meta: { next_cursor: null } }));
        const client = createClient({ token: 'vsn_abc', workspaceId: 'ws-1', fetch: fetchMock as unknown as typeof fetch });

        const result = await client.listContacts({ 'page[size]': 25, 'filter[search]': 'ali raza' });

        expect(result.meta.next_cursor).toBeNull();
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/api/v1/contacts?page%5Bsize%5D=25&filter%5Bsearch%5D=ali%20raza');
        expect(init.method).toBe('GET');
        expect(init.headers.Authorization).toBe('Bearer vsn_abc');
        expect(init.headers['X-Workspace-Id']).toBe('ws-1');
    });

    it('encodes path parameters and posts JSON bodies', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(200, { data: { id: 'c/1' } }))
            .mockResolvedValueOnce(jsonResponse(201, { token: 'vsn_t', token_type: 'Bearer', expires_at: null }));
        const client = createClient({ baseUrl: 'https://api.example.test/api/v1', fetch: fetchMock as unknown as typeof fetch });

        await client.getContact('c/1');
        expect(fetchMock.mock.calls[0][0]).toBe('https://api.example.test/api/v1/contacts/c%2F1');

        const token = await client.createAccessToken({ email: 'a@example.test', password: 'p', device_name: 'iPhone' });
        expect(token.token_type).toBe('Bearer');
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ email: 'a@example.test', password: 'p', device_name: 'iPhone' });
        expect(fetchMock.mock.calls[1][1].headers['Content-Type']).toBe('application/json');
    });

    it('throws ApiError carrying RFC 9457 problem details', async () => {
        const problem = { type: 'https://docs.vsn.marketing/problems/forbidden', title: 'Forbidden', status: 403, code: 'forbidden' };
        const client = createClient({ fetch: vi.fn().mockImplementation(async () => jsonResponse(403, problem, 'application/problem+json')) as unknown as typeof fetch });

        await expect(client.getMe()).rejects.toMatchObject({ status: 403, problem: { code: 'forbidden' } });
        await expect(client.getMe()).rejects.toBeInstanceOf(ApiError);
    });

    it('returns undefined for 204 responses', async () => {
        const client = createClient({ fetch: vi.fn().mockResolvedValue(new Response(null, { status: 204 })) as unknown as typeof fetch });
        await expect(client.revokeCurrentAccessToken()).resolves.toBeUndefined();
    });
});
