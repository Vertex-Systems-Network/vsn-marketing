// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import Workspaces from './workspaces';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
afterEach(cleanup);

test('renders only server-supplied workspace and module links', () => {
    render(<Workspaces workspaces={[{ name: 'Authorized workspace', links: [{ name: 'Analytics', url: '/workspaces/own/analytics' }] }]}
        previous_url={null} next_url={null} />);
    expect(screen.getByRole('heading', { name: 'Authorized workspace' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Analytics' })).toHaveAttribute('href', '/workspaces/own/analytics');
    expect(screen.queryByText('Other workspace')).not.toBeInTheDocument();
});

test('shows revoked workspace reauthentication without stale module links', () => {
    render(<Workspaces workspaces={[{ name: 'Revoked workspace', links: [], reauth_required: true }]}
        previous_url={null} next_url={null} />);
    expect(screen.getByText(/Workspace session revoked/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Analytics' })).not.toBeInTheDocument();
});
