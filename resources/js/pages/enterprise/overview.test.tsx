// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import EnterpriseOverview from './overview';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
afterEach(cleanup);

test('labels capability states and exposes only the supplied audit export link', () => {
    render(<EnterpriseOverview workspace_id="workspace-one" audit_export_url="/workspaces/workspace-one/audit/export"
        capabilities={[{ name: 'Federated sign-in', status: 'Not configured' },
            { name: 'SCIM deactivation', status: 'Disabled until integration approval' }]} />);
    expect(screen.getByRole('heading', { name: 'Enterprise controls' })).toBeInTheDocument();
    expect(screen.getByText('Not configured')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Open audit metadata' }))
        .toHaveAttribute('href', '/workspaces/workspace-one/audit/export');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});
