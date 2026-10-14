// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import BrandPreview from './brand-preview';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
afterEach(cleanup);

test('keeps the preview and canonical disclosures readable without interpreting a brand name as markup', () => {
    const { container } = render(<BrandPreview display_name={'<script>spoof()</script>'}
        accent_color="#225588" logo_asset_id={null} revision={2} />);

    expect(screen.getByRole('heading', { name: 'Brand appearance preview', level: 1 })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Brand preview' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: '<script>spoof()</script>', level: 2 })).toBeInTheDocument();
    expect(container.querySelector('script')).toBeNull();
    expect(screen.getByText(/Account origin, consent ownership and billing disclosures remain with VSN Marketing/))
        .toBeInTheDocument();
    expect(screen.getByText('Sample action')).toHaveStyle({ backgroundColor: '#225588' });
});

test('saves only appearance fields with a session token and preserves the existing logo', async () => {
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = 'test-session-token';
    document.head.append(meta);
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
        ok: true, json: async () => ({ revision: 3 }),
    } as Response);
    render(<BrandPreview display_name="Original" accent_color="#225588"
        logo_asset_id="logo-id" revision={2} />);

    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Edited brand' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Save appearance' }).closest('form')!);
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('revision 3'));

    const [url, request] = fetchMock.mock.calls[0];
    expect(url).toBe(window.location.pathname);
    expect(request).toMatchObject({ method: 'POST', credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': 'test-session-token' } });
    expect(JSON.parse(request!.body as string)).toEqual({
        display_name: 'Edited brand', accent_color: '#225588', logo_asset_id: 'logo-id',
    });
    fetchMock.mockRestore();
    meta.remove();
});
