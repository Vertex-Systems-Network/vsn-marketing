// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import Login from './login';

vi.mock('@inertiajs/react', () => ({ Head: () => null }));
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

test('labels credentials and prevents duplicate requests while sign in is pending', async () => {
    let finish!: (value: unknown) => void;
    const fetcher = vi.fn(() => new Promise(resolve => { finish = resolve; }));
    vi.stubGlobal('fetch', fetcher);
    render(<Login />);
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'user@example.test' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'example-password' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Sign in' }).closest('form')!);
    fireEvent.submit(screen.getByRole('button', { name: 'Signing in…' }).closest('form')!);
    expect(fetcher).toHaveBeenCalledTimes(1);
    finish({ ok: false, status: 429 });
    expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts');
    await waitFor(() => expect(screen.getByRole('button', { name: 'Sign in' })).toBeEnabled());
});
