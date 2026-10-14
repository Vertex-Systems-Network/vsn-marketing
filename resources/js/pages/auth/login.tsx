import { Head } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';

export default function Login() {
    const submitting = useRef(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (submitting.current) return;
        submitting.current = true;
        setBusy(true);
        setError('');
        const form = event.currentTarget;
        const payload = new FormData(form);
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch('/auth/login', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token ?? '' },
                body: payload,
            });
            if (response.ok) {
                window.location.assign('/workspaces');
                return;
            }
            setError(response.status === 429 ? 'Too many attempts. Please try again later.' : 'Sign in failed. Check your email and password.');
        } catch {
            setError('Connection failed. Please try again.');
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    }

    return <><Head title="Sign in" /><main className="mx-auto flex min-h-screen max-w-md items-center px-6 py-12">
        <section className="w-full space-y-6"><h1 className="text-3xl font-semibold">Sign in to VSN Marketing</h1>
            <form onSubmit={submit} className="space-y-4">
                <label className="block">Email <input className="mt-1 block w-full rounded border border-neutral-500 bg-neutral-900 p-3" name="email" type="email" autoComplete="username" required /></label>
                <label className="block">Password <input className="mt-1 block w-full rounded border border-neutral-500 bg-neutral-900 p-3" name="password" type="password" autoComplete="current-password" required /></label>
                {error && <p role="alert" className="text-red-300">{error}</p>}
                <button disabled={busy} className="rounded bg-sky-300 px-5 py-3 font-semibold text-neutral-950 disabled:opacity-50">{busy ? 'Signing in…' : 'Sign in'}</button>
            </form>
        </section>
    </main></>;
}
