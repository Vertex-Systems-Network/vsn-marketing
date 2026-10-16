import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Capability = { name: string; status: string };
type Props = { workspace_id: string; audit_export_url: string; capabilities: Capability[] };
type AuditItem = { id: string; action: string; actor_id: string | null; subject_type: string | null; subject_id: string | null; occurred_at: string };
type AuditPage = { items: AuditItem[]; next_cursor: string | null };

export default function EnterpriseOverview({ workspace_id, audit_export_url, capabilities }: Props) {
    const [items, setItems] = useState<AuditItem[]>([]);
    const [cursor, setCursor] = useState<string | null>(null);
    const [loaded, setLoaded] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    async function loadAudit(before?: string) {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const url = new URL(audit_export_url, window.location.origin);
            url.searchParams.set('limit', '25');
            if (before) url.searchParams.set('before', before);
            const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Audit metadata could not be loaded.');
            const page = await response.json() as AuditPage;
            if (!Array.isArray(page.items) || (page.next_cursor !== null && typeof page.next_cursor !== 'string')) {
                throw new Error('Audit metadata response was invalid.');
            }
            setItems(previous => before ? [...previous, ...page.items] : page.items);
            setCursor(page.next_cursor);
            setLoaded(true);
        } catch {
            setError('Audit metadata could not be loaded. Try again.');
        } finally {
            setBusy(false);
        }
    }

    return <><Head title="Enterprise governance" />
        <main className="min-h-screen bg-neutral-950 px-4 py-8 text-neutral-100 sm:px-6">
            <div className="mx-auto max-w-3xl">
                <header>
                    <p className="text-sm font-semibold text-sky-300">VSN Marketing · Workspace governance</p>
                    <h1 className="mt-2 text-3xl font-semibold">Enterprise controls</h1>
                    <p className="mt-3 text-neutral-300">Review the current capabilities for this workspace. Pending integration and production controls are shown as unavailable.</p>
                    <p className="mt-2 break-all text-sm text-neutral-400">Workspace: {workspace_id}</p>
                </header>
                <section aria-labelledby="governance-capabilities" className="mt-8 rounded-2xl border border-white/20 p-5">
                    <h2 id="governance-capabilities" className="text-xl font-semibold">Capability status</h2>
                    <dl className="mt-4 divide-y divide-white/10">
                        {capabilities.map(({ name, status }) => <div key={name} className="py-3 sm:grid sm:grid-cols-2 sm:gap-5">
                            <dt className="font-medium">{name}</dt><dd className="mt-1 text-neutral-300 sm:mt-0">{status}</dd>
                        </div>)}
                    </dl>
                </section>
                <section aria-labelledby="governance-audit" className="mt-6 rounded-2xl border border-white/20 p-5">
                    <h2 id="governance-audit" className="text-xl font-semibold">Audit export</h2>
                    <p className="mt-2 text-neutral-300">The JSON response includes bounded event metadata. Audit evidence and secrets are excluded.</p>
                    <button type="button" disabled={busy} onClick={() => void loadAudit()}
                        className="mt-4 rounded-xl border border-sky-300 px-4 py-2 font-semibold text-sky-300 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-300">
                        {busy ? 'Loading…' : 'Review audit metadata'}
                    </button>
                    <a className="mt-4 inline-block rounded-xl bg-sky-300 px-4 py-2 font-semibold text-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-300"
                        href={audit_export_url}>Open audit metadata</a>
                    <p role="alert" className="mt-3 text-amber-200">{error}</p>
                    {loaded && <div className="mt-5">
                        {items.length === 0 ? <p>No audit events on this page.</p> : <ul aria-label="Audit events" className="divide-y divide-white/20">
                            {items.map(item => <li key={item.id} className="py-3">
                                <p className="font-medium">{item.action}</p>
                                <p className="break-all text-sm text-neutral-300">{item.occurred_at} · Actor: {item.actor_id ?? 'System'} · Subject: {item.subject_type ?? 'None'} {item.subject_id ?? ''}</p>
                            </li>)}
                        </ul>}
                        {cursor && <button type="button" disabled={busy} onClick={() => void loadAudit(cursor)}
                            className="mt-4 rounded border border-white/40 px-4 py-2 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-300">Load older events</button>}
                    </div>}
                </section>
            </div>
        </main></>;
}
