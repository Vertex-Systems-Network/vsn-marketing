// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { expect, test, vi } from 'vitest';
import Welcome from './welcome';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

test('renders the product entry and sign in link', () => {
    render(<Welcome />);

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
        'Provider-agnostic marketing infrastructure, built on deterministic guardrails.',
    );
    expect(screen.getByText(/Workspace publishing, journeys, segments, and analytics/)).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Sign in' }).getAttribute('href')).toBe('/auth/login');
});
