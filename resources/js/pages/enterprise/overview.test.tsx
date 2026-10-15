// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import EnterpriseOverview from './overview';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
afterEach(cleanup);

test('labels capability states and exposes the authorized audit export link', () => {
    render(<EnterpriseOverview workspace_id="workspace-one" audit_export_url="/workspaces/workspace-one/audit/export"
        capabilities={[{ name: 'Federated sign-in', status: 'Not configured' },
            { name: 'SCIM deactivation', status: 'Disabled until integration approval' }]} />);
    expect(screen.getByRole('heading', { name: 'Enterprise controls' })).toBeInTheDocument();
    expect(screen.getByText('Not configured')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open audit metadata' }))
        .toHaveAttribute('href', '/workspaces/workspace-one/audit/export');
    expect(screen.getByRole('button', { name: 'Review audit metadata' })).toBeInTheDocument();
});

test('reviews bounded audit pages without requesting or rendering evidence', async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce({ ok: true, json: async () => ({
        items: [{ id: 'first', action: 'identity.role.approved', actor_id: 'actor-one', subject_type: null, subject_id: null, occurred_at: '2026-10-15' }],
        next_cursor: 'first',
    }) }).mockResolvedValueOnce({ ok: true, json: async () => ({
        items: [{ id: 'second', action: 'identity.role.assigned', actor_id: null, subject_type: 'user', subject_id: 'member-two', occurred_at: '2026-10-14' }],
        next_cursor: null,
    }) });
    vi.stubGlobal('fetch', fetchMock);
    render(<EnterpriseOverview workspace_id="workspace-one" audit_export_url="/workspaces/workspace-one/audit/export" capabilities={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review audit metadata' }));
    await screen.findByText('identity.role.approved');
    expect(fetchMock).toHaveBeenCalledWith(expect.stringContaining('limit=25'), expect.objectContaining({ cache: 'no-store', credentials: 'same-origin' }));
    fireEvent.click(screen.getByRole('button', { name: 'Load older events' }));
    await screen.findByText('identity.role.assigned');
    expect(fetchMock.mock.calls[1][0]).toContain('before=first');
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
    expect(screen.queryByRole('button', { name: 'Load older events' })).not.toBeInTheDocument();
    vi.unstubAllGlobals();
});

test('reports export errors without exposing a stale page', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
    render(<EnterpriseOverview workspace_id="workspace-one" audit_export_url="/workspaces/workspace-one/audit/export" capabilities={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Review audit metadata' }));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Audit metadata could not be loaded. Try again.'));
    expect(screen.queryByRole('list', { name: 'Audit events' })).not.toBeInTheDocument();
    vi.unstubAllGlobals();
});
