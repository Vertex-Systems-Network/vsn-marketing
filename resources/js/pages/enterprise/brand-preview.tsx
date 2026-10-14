import { Head } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type Props = { display_name: string; accent_color: string; logo_asset_id: string | null; revision: number };

export default function BrandPreview({ display_name, accent_color, logo_asset_id, revision }: Props) {
    const [name, setName] = useState(display_name);
    const [color, setColor] = useState(accent_color);
    const [logoId, setLogoId] = useState(logo_asset_id);
    const [savedRevision, setSavedRevision] = useState(revision);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const [error, setError] = useState('');

    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSaving(true);
        setError('');
        setMessage('');
        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            if (!csrf) throw new Error('Session token is unavailable. Refresh the page.');
            const response = await fetch(window.location.pathname, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ display_name: name, accent_color: color, logo_asset_id: logoId, expected_revision: savedRevision }),
            });
            if (response.status === 409) throw new Error('Appearance changed in another session. Reload before saving.');
            if (!response.ok) throw new Error('Appearance could not be saved. Check the name, color and permissions.');
            const result: { revision: number } = await response.json();
            setSavedRevision(result.revision);
            setMessage(`Appearance saved at revision ${result.revision}.`);
        } catch (cause) {
            setError(cause instanceof Error ? cause.message : 'Appearance could not be saved.');
        } finally {
            setSaving(false);
        }
    }

    return <><Head title="Brand appearance preview" />
        <main className="min-h-screen bg-neutral-950 px-4 py-8 text-neutral-100">
            <div className="mx-auto max-w-2xl">
                <h1 className="text-3xl font-semibold">Brand appearance preview</h1>
                <p className="mt-2 text-neutral-300">Review the name and accent color, revision {savedRevision}.</p>
                <section aria-label="Brand preview" className="mt-8 rounded-2xl border border-white/20 p-6">
                    <h2 className="text-2xl font-semibold">{name}</h2>
                    <div className="mt-6 rounded-lg px-4 py-3 font-semibold text-white" style={{ backgroundColor: /^#[0-9a-f]{6}$/i.test(color) ? color : '#225588' }}>
                        Sample action
                    </div>
                </section>
                <form onSubmit={save} className="mt-6 space-y-4 rounded-2xl border border-white/20 p-6">
                    <h2 className="text-xl font-semibold">Appearance settings</h2>
                    <label className="block text-sm font-medium" htmlFor="brand-display-name">Display name</label>
                    <input id="brand-display-name" required maxLength={80} value={name} onChange={event => setName(event.target.value)}
                        className="w-full rounded-lg border border-white/30 bg-neutral-900 px-3 py-2 text-white" />
                    <label className="block text-sm font-medium" htmlFor="brand-accent-color">Accent color</label>
                    <input id="brand-accent-color" type="color" value={color} onChange={event => setColor(event.target.value)}
                        className="h-12 w-20 rounded-lg border border-white/30 bg-neutral-900" />
                    {logoId && <p className="text-sm text-neutral-300">Current logo asset: {logoId}</p>}
                    {logoId && <button type="button" onClick={() => setLogoId(null)}
                        className="block rounded-lg border border-white/40 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2">
                        Remove logo on save
                    </button>}
                    <p className="text-sm text-neutral-300">The accent color must remain readable with white text. Logo upload is managed separately.</p>
                    <button type="submit" disabled={saving}
                        className="rounded-lg bg-sky-300 px-4 py-2 font-semibold text-neutral-950 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2">
                        {saving ? 'Saving…' : 'Save appearance'}
                    </button>
                    {message && <p role="status" className="text-sm text-green-300">{message}</p>}
                    {error && <p role="alert" className="text-sm text-red-300">{error}</p>}
                </form>
                <p className="mt-6 text-sm text-neutral-300">Account origin, consent ownership and billing disclosures remain with VSN Marketing.</p>
            </div>
        </main></>;
}
