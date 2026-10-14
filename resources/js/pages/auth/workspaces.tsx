import { Head } from '@inertiajs/react';
import { useState } from 'react';

type Workspace = { name: string; links: { name: string; url: string }[]; reauth_required?: boolean };
type Props = { workspaces: Workspace[]; previous_url: string | null; next_url: string | null };

export default function Workspaces({ workspaces, previous_url, next_url }: Props) {
    const [busy, setBusy] = useState(false);
    async function signOut() {
        if (busy) return;
        setBusy(true);
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch('/auth/logout', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-TOKEN': token ?? '' } });
            if (response.ok) window.location.assign('/auth/login');
            else setBusy(false);
        } catch { setBusy(false); }
    }
    return <><Head title="Your workspaces" /><main className="mx-auto max-w-4xl space-y-8 px-6 py-12">
        <header className="flex items-center justify-between gap-4"><h1 className="text-3xl font-semibold">Your workspaces</h1>
            <button onClick={signOut} disabled={busy} className="rounded border border-neutral-400 px-4 py-2">Sign out</button></header>
        {workspaces.length === 0 && <p>No workspaces on this page.</p>}
        <ul className="space-y-5">{workspaces.map((workspace, index) => <li key={`${workspace.name}-${index}`} className="rounded border border-neutral-700 p-5">
            <h2 className="text-xl font-medium">{workspace.name}</h2>
            {workspace.reauth_required ? <p className="mt-2 text-amber-200">Workspace session revoked. Sign out, then sign in again.</p> :
                workspace.links.length === 0 ? <p className="mt-2 text-neutral-300">No module access assigned.</p> :
                <ul className="mt-3 flex flex-wrap gap-3">{workspace.links.map(link => <li key={link.url}><a className="text-sky-300 underline focus-visible:outline-2" href={link.url}>{link.name}</a></li>)}</ul>}
        </li>)}</ul>
        <nav aria-label="Workspace pages" className="flex gap-4">{previous_url && <a href={previous_url}>Previous</a>}{next_url && <a href={next_url}>Next</a>}</nav>
    </main></>;
}
