import { Head } from '@inertiajs/react';

type Capability = { name: string; status: string };
type Props = { workspace_id: string; audit_export_url: string; capabilities: Capability[] };

export default function EnterpriseOverview({ workspace_id, audit_export_url, capabilities }: Props) {
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
                    <a className="mt-4 inline-block rounded-xl bg-sky-300 px-4 py-2 font-semibold text-neutral-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-300"
                        href={audit_export_url}>Open audit metadata</a>
                </section>
            </div>
        </main></>;
}
