// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { expect, test, vi } from 'vitest';
import Welcome from './welcome';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
}));

test('renders the phase-one foundation message', () => {
    render(<Welcome />);

    expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(
        'Provider-agnostic marketing infrastructure, built on deterministic guardrails.',
    );
    expect(screen.getByText(/PHASE-01 foundation runtime is active/)).toBeTruthy();
});
