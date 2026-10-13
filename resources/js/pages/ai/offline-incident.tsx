import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export type OfflineIncidentReview = {
    status: string; reason_code: string; run_id: string; latest_audit_event_id: string | null;
    audit_sequence: number; case_count: number; severity_counts: Record<string, number>;
    category_counts: Record<string, number>; audit_history_integrity_checked: boolean;
    human_triage_required: boolean; synthetic_evidence_only: boolean;
    production_incident_verified: false; incident_resolved: false; recovery_performed: false;
    execution_authorized: false; promotion_authorized: false;
};
type Triage = { status: string; triage_sequence: number; current_review_triaged: boolean;
    recovery_review_status: string; recovery_performed: false };
type Props = { review: OfflineIncidentReview | null; triage: Triage | null; triage_available: boolean;
    triage_action: string; notice: string | null };

export default function OfflineIncidentPage({ review, triage, triage_available, triage_action, notice }: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const decide = (decision: 'investigating' | 'escalated') => {
        if (!review?.latest_audit_event_id || busy) return;
        setBusy(true); setError('');
        router.post(triage_action, { audit_event_id: review.latest_audit_event_id, decision }, {
            preserveScroll: true,
            onError: () => setError('Triage could not be recorded. Refresh the review and check your access.'),
            onFinish: () => setBusy(false),
        });
    };
    return <><Head title="Offline incident review" />
        <main className="min-h-screen bg-neutral-950 px-4 py-8 text-neutral-100 sm:px-6">
            <div className="mx-auto max-w-4xl">
                <header><p className="text-sm font-semibold text-sky-300">VSN Marketing · Offline safety</p>
                    <h1 className="mt-2 text-3xl font-semibold">Synthetic incident review</h1>
                    <p className="mt-3 text-neutral-300">This view covers offline test evidence only. It does not verify a production incident, clear a safety hold, approve execution or perform recovery.</p>
                </header>
                {review === null ? <p role="alert" className="mt-6 rounded-xl border border-amber-300 p-4">The audit history cannot be verified. Escalate for independent investigation.</p>
                    : <><section aria-labelledby="incident-status" className="mt-6 rounded-2xl border border-white/20 p-5">
                        <h2 id="incident-status" className="text-xl font-semibold">Current review</h2>
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div><dt className="text-neutral-400">Status</dt><dd className="font-semibold">{review.status.replaceAll('_', ' ')}</dd></div>
                            <div><dt className="text-neutral-400">Reason</dt><dd>{review.reason_code.replaceAll('_', ' ')}</dd></div>
                            <div><dt className="text-neutral-400">Run</dt><dd className="break-all">{review.run_id}</dd></div>
                            <div><dt className="text-neutral-400">Audit sequence</dt><dd>{review.audit_sequence}</dd></div>
                            <div><dt className="text-neutral-400">Synthetic cases</dt><dd>{review.case_count}</dd></div>
                            <div><dt className="text-neutral-400">Audit history</dt><dd>{review.audit_history_integrity_checked ? 'Structural checks passed' : 'Unavailable'}</dd></div>
                        </dl>
                        <p role="status" className="mt-5 text-amber-200">Human triage required. Recovery and production verification remain unconfirmed.</p>
                    </section>
                    <section aria-labelledby="incident-counts" className="mt-6 rounded-2xl border border-white/20 p-5">
                        <h2 id="incident-counts" className="text-xl font-semibold">Redacted case counts</h2>
                        <div className="mt-4 grid gap-5 sm:grid-cols-2">
                            {([['Category', review.category_counts], ['Severity', review.severity_counts]] as const).map(([label, counts]) =>
                                <div key={label}><h3 className="font-semibold">{label}</h3><dl className="mt-2 space-y-2 text-sm">
                                    {Object.entries(counts).map(([key, value]) => <div className="flex justify-between gap-4" key={key}><dt>{key.replaceAll('_', ' ')}</dt><dd>{value}</dd></div>)}
                                </dl></div>)}</div>
                    </section>
                    <section aria-labelledby="incident-triage" className="mt-6 rounded-2xl border border-white/20 p-5">
                        <h2 id="incident-triage" className="text-xl font-semibold">Independent triage</h2>
                        <p className="mt-2 text-sm text-neutral-300">A separate authorized reviewer can record an investigation or escalation against this exact audit event. Neither action resolves the incident or releases a hold.</p>
                        {triage && <p role="status" className="mt-3 text-sm text-neutral-300">Latest triage: {triage.status.replaceAll('_', ' ')} · {triage.triage_sequence} recorded event(s). {triage.current_review_triaged ? 'Current review acknowledged.' : 'Current review needs acknowledgement.'} Recovery remains blocked without verified external effect evidence.</p>}
                        {triage_available ? <div className="mt-4 flex flex-wrap gap-3">
                            <button type="button" disabled={busy || Boolean(triage?.current_review_triaged && triage.status === 'escalated')} onClick={() => decide('investigating')} className="rounded-xl bg-sky-300 px-4 py-2 font-semibold text-neutral-950 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-300">Record investigating</button>
                            <button type="button" disabled={busy} onClick={() => decide('escalated')} className="rounded-xl border border-amber-300 px-4 py-2 font-semibold text-amber-200 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-300">Escalate</button>
                        </div> : <p role="status" className="mt-4 text-amber-200">Triage is unavailable for this account or audit event.</p>}
                        {notice && <p role="status" aria-live="polite" className="mt-4 text-sky-200">{notice === 'unavailable' ? 'Triage was not recorded. Refresh and investigate the audit.' : `Triage recorded: ${notice}. The safety hold remains.`}</p>}
                        {error && <p role="alert" className="mt-4 text-amber-200">{error}</p>}
                    </section></>}
            </div>
        </main></>;
}
