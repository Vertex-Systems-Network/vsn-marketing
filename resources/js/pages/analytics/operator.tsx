import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import OfflineAutonomyPreviewPanel, { type OfflineAutonomyPreview } from './autonomy-preview';

type Report = { id: string; fingerprint: string; definition_hash: string; definition: Record<string, unknown>;
    start_utc: string; end_utc: string; receipt_cutoff_utc: string; latest_receipt_utc: string | null;
    source_completeness: string; excluded: number; lineage_count: number; metrics: Record<string, number>;
    quality: Record<string, number>; censored_subjects: number | null };
type Schedule = { id: string; kind: string; enabled: boolean; next_window_end: string; status_code: string | null };
type ProviderEngagement = { id: string; provider_key: string; metric: string; value: number;
    definition: { provider_key: string; provider_metric: string; unit: string; version: number; semantics: string;
        cross_provider_equivalent: boolean; limitation: string };
    observed_at: string; received_at: string; receipt_lag_seconds: number; delayed: boolean;
    provider_total_status: string; source_completeness: string; missing_provider_events: string;
    source_lineage_hash: string; fingerprint: string };
type Quality = { id: string; fingerprint: string; source_hash: string; event_type: string; start_utc: string; end_utc: string;
    coverage: string; expected_total: number | null; missing_source_keys: number | null; unexpected_source_keys: number | null;
    missing_projection: number; duplicates: number; conflicts: number; late: number; drifted_hashes: number;
    max_receipt_lag_seconds: number; affected_metric_versions: { snapshot_id: string; definition_hash: string; version: number }[] };
type Props = { state: string; reports: Report[]; quality_reports?: Quality[]; provider_engagement?: ProviderEngagement[]; quality_event_types?: string[]; schedules: Schedule[]; invalidated_reports: number;
    report_kinds: string[]; default_start: string; default_end: string; notice: string | null;
    insight: { status?: string; baseline_n?: number; z_score?: number; output?: { facts: { metric: string; value: number }[]; inferences: string[] } } | null; explanation_available: boolean; offline_autonomy_preview?: OfflineAutonomyPreview | null; autonomy_report_options?: { id: string; label: string }[];
    actions: { generate: string; schedules: string; base: string; autonomy_preview?: string } };
const notices: Record<string, string> = { autonomy_preview_created: 'Server-issued offline preview recorded. Sending and promotion remain disabled.', autonomy_preview_denied: 'Offline preview request denied. Check report access, permission and purpose.', quality_created: 'Immutable source quality check created.', quality_denied: 'Quality check denied. Review scope, dates and observation bounds.', report_created: 'Immutable report created.', report_denied: 'Report could not be generated. Check dates, purpose, permissions and observation bounds.',
    schedule_created: 'Daily UTC schedule created. Reports stay inside this workspace.', schedule_denied: 'Schedule could not be created.',
    schedule_disabled: 'Schedule disabled.', explanation_unavailable: 'No approved explanation route is configured.', insight_denied: 'Insight evidence could not be validated.' };
const control = 'mt-2 w-full rounded-xl border border-white/20 bg-neutral-900 px-3 py-2 text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-300';
const button = 'rounded-xl bg-sky-300 px-4 py-2 font-semibold text-neutral-950 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-sky-300';
export default function AnalyticsOperator(p: Props) {
    const [source, setSource] = useState('');
    const [autonomyReport, setAutonomyReport] = useState(p.autonomy_report_options?.[0]?.id ?? '');
    const [autonomyTarget, setAutonomyTarget] = useState('10');
    const [qualityType, setQualityType] = useState('product.viewed');
    const [kind, setKind] = useState('counts');
    const [start, setStart] = useState(p.default_start);
    const [end, setEnd] = useState(p.default_end);
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = useState('');
    const [baseline, setBaseline] = useState<string[]>([]);
    const [error, setError] = useState('');
    const post = (url: string, data: Record<string, string | string[]>) => {
        if (busy) return;
        setBusy(true); setError('');
        router.post(url, data, { preserveScroll: true, onFinish: () => setBusy(false),
            onError: () => setError('The request failed validation. Review the fields and retry.') });
    };
    const unavailable = p.state !== 'ready';
    return <><Head title="Analytics reports" /><main className="min-h-screen bg-neutral-950 text-neutral-100">
        <div className="mx-auto max-w-6xl px-4 py-8 sm:px-6">
            <header><p className="text-sm font-semibold text-sky-300">VSN Marketing · Analytics</p>
                <h1 className="mt-2 text-3xl font-semibold">Analytics reports</h1>
                <p className="mt-3 max-w-3xl text-neutral-300">Review admitted events, funnels, retention and revenue in UTC. Reports disclose their definition and receipt cutoff. Source coverage remains unknown; attribution describes credit and does not prove causal lift.</p></header>
            {p.actions.autonomy_preview && <section aria-labelledby="autonomy-preview-form" className="mt-6 rounded-2xl border border-white/15 p-5" aria-busy={busy}>
                <h2 id="autonomy-preview-form" className="text-xl font-semibold">Create a read-only AI marketing preview</h2>
                <p className="mt-2 text-sm text-neutral-300">Choose an already-authorized measured counts report. This only records a bounded, offline analytics-review proposal, not a campaign, message, AI provider call or verified uplift.</p>
                <form className="mt-4 grid gap-4 sm:grid-cols-3" onSubmit={(e) => { e.preventDefault();
                    if (p.actions.autonomy_preview) post(p.actions.autonomy_preview, { report_id: autonomyReport, target_count: autonomyTarget });
                }}>
                    <label>Measured counts report
                        <select className={control} value={autonomyReport} required onChange={(e) => setAutonomyReport(e.target.value)}>
                            {(p.autonomy_report_options ?? []).map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}
                        </select>
                    </label>
                    <label>Review target count
                        <input className={control} type="number" min={1} max={1000000} step={1} required value={autonomyTarget} onChange={(e) => setAutonomyTarget(e.target.value)} />
                    </label>
                    <div className="flex items-end"><button className={button} disabled={busy || unavailable || !autonomyReport
                        || !Number.isInteger(Number(autonomyTarget)) || Number(autonomyTarget) < 1 || Number(autonomyTarget) > 1000000}>
                        {busy ? 'Working…' : 'Create offline preview'}
                    </button></div>
                </form>
                {(p.autonomy_report_options ?? []).length === 0 && <p className="mt-3 text-amber-200">No authorized measured counts report is available for preview.</p>}
            </section>}
            {p.offline_autonomy_preview !== undefined && <div className="mt-6"><OfflineAutonomyPreviewPanel preview={p.offline_autonomy_preview} /></div>}
            {unavailable && <p role="alert" className="mt-6 rounded-xl border border-amber-300 p-4">Analytics purpose or retention approval is unavailable. Reports and schedules cannot be generated.</p>}
            <div role="status" aria-live="polite" className="mt-4 text-sky-200">{busy ? 'Working…' : p.notice ? notices[p.notice] ?? 'Request finished.' : ''}</div>
            {error && <p role="alert" className="mt-3 text-amber-200">{error}</p>}
            {p.invalidated_reports > 0 && <p role="status" className="mt-3 text-amber-200">{p.invalidated_reports} reports are unavailable because current privacy or lineage checks failed.</p>}
            <section aria-labelledby="create-report" className="mt-6 rounded-2xl border border-white/15 p-5" aria-busy={busy}>
                <h2 id="create-report" className="text-xl font-semibold">Create an immutable report</h2>
                <form onSubmit={(e) => { e.preventDefault(); post(p.actions.generate, { kind, start, end }); }}>
                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                        <label>Report kind<select className={control} value={kind} onChange={(e) => setKind(e.target.value)}>{p.report_kinds.map((k) => <option key={k}>{k}</option>)}</select></label>
                        <label>Start date (UTC, inclusive)<input className={control} type="date" value={start} onChange={(e) => setStart(e.target.value)} required /></label>
                        <label>End date (UTC, exclusive)<input className={control} type="date" value={end} onChange={(e) => setEnd(e.target.value)} required /></label>
                    </div><div className="mt-5 flex flex-wrap gap-3"><button className={button} disabled={busy || unavailable || start >= end}>{busy ? 'Working…' : 'Generate report'}</button>
                        <button className={button} type="button" disabled={busy || unavailable} onClick={() => post(p.actions.schedules, { kind })}>Schedule daily UTC report</button></div>
                </form><p className="mt-4 text-sm text-neutral-300">Daily schedules cover the previous complete UTC day. Current owner authority is checked again at execution. No external delivery is enabled.</p>
            </section>
            <section aria-labelledby="report-history" className="mt-7"><h2 id="report-history" className="text-xl font-semibold">Report history</h2>
                {p.reports.length === 0 ? <p className="mt-4 text-neutral-300">No valid reports in this scope.</p> : <div className="mt-4 grid gap-5">{p.reports.map((r) => <article key={r.id} className="min-w-0 rounded-2xl border border-white/15 p-5">
                    <h3 className="text-lg font-semibold">{String(r.definition.kind ?? r.definition.event_type)} · version {String(r.definition.version)}</h3>
                    <p className="mt-2 break-words text-sm text-neutral-300">UTC period: {r.start_utc} to {r.end_utc}. Receipt cutoff: {r.receipt_cutoff_utc}.</p>
                    <p className="mt-2 text-sm text-neutral-300">Source completeness: {r.source_completeness}. Latest local receipt: {r.latest_receipt_utc ?? 'unknown'}. Excluded facts: {r.excluded}. Lineage references: {r.lineage_count}.</p>
                    {r.censored_subjects !== null && <p className="mt-2 text-sm text-amber-200">Incomplete cohort horizons: {r.censored_subjects} subjects.</p>}
                    <div className="mt-4 overflow-x-auto rounded-lg focus-visible:outline-2 focus-visible:outline-sky-300" tabIndex={0} role="region" aria-label="Report metrics">
                        <table className="w-full text-left text-sm"><caption className="mb-2 text-left text-neutral-300">Measured values; revenue amounts are integer currency minor units</caption>
                            <thead><tr><th scope="col" className="p-2">Metric</th><th scope="col" className="p-2">Value</th></tr></thead><tbody>{Object.entries(r.metrics).map(([metric, value]) => <tr key={metric}><th scope="row" className="break-words border-t border-white/10 p-2 font-normal">{metric}</th><td className="border-t border-white/10 p-2">{value}</td></tr>)}</tbody></table>
                    </div>
                    {Object.values(r.quality).some((n) => n > 0) && <p className="mt-3 text-amber-200">Data quality requires review: {Object.entries(r.quality).filter(([, n]) => n > 0).map(([k, n]) => `${k}: ${n}`).join('; ')}.</p>}
                    <details className="mt-4"><summary className="cursor-pointer text-sky-200 focus-visible:outline-2 focus-visible:outline-sky-300">Definition and evidence fingerprint</summary>
                        <pre className="mt-2 whitespace-pre-wrap break-all text-xs text-neutral-300">{JSON.stringify(r.definition, null, 2)}</pre><p className="mt-2 break-all text-xs">Snapshot: {r.id}<br />Fingerprint: {r.fingerprint}<br />Definition: {r.definition_hash}</p></details>
                </article>)}</div>}
            </section>
            <section aria-labelledby="provider-engagement" className="mt-7 rounded-2xl border border-white/15 p-5">
                <h2 id="provider-engagement" className="text-xl font-semibold">Provider engagement evidence</h2>
                <p className="mt-3 text-neutral-300">Provider-reported aggregate metrics stay source-specific. They are not summed or compared across providers unless a separate verified mapping exists. Source completeness and missing provider events remain unknown without independent provider evidence.</p>
                {(p.provider_engagement ?? []).length === 0 && <p className="mt-4 text-neutral-300">No retained provider aggregate evidence in this scope.</p>}
                {(p.provider_engagement ?? []).map((fact) => <article key={fact.id} className="mt-5 rounded-xl border border-white/15 p-4">
                    <h3 className="font-semibold">{fact.provider_key} · {fact.metric}</h3>
                    <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <dt>Provider-reported value</dt><dd>{fact.value}</dd>
                        <dt>Metric unit / version</dt><dd>{fact.definition.unit} / v{fact.definition.version}</dd>
                        <dt>Observed at</dt><dd>{fact.observed_at}</dd>
                        <dt>Received at</dt><dd>{fact.received_at}</dd>
                        <dt>Receipt lag</dt><dd>{fact.receipt_lag_seconds} seconds{fact.delayed ? ' · delayed receipt' : ''}</dd>
                        <dt>Provider total status</dt><dd>{fact.provider_total_status.replaceAll('_', ' ')}</dd>
                        <dt>Source completeness</dt><dd>{fact.source_completeness}</dd>
                        <dt>Missing provider events</dt><dd>{fact.missing_provider_events}</dd>
                    </dl>
                    <p className="mt-3 text-sm text-amber-200">{fact.definition.limitation}</p>
                    <details className="mt-3"><summary className="cursor-pointer text-sky-200">Definition and hashed lineage evidence</summary>
                        <pre className="mt-2 whitespace-pre-wrap break-all text-xs text-neutral-300">{JSON.stringify(fact.definition, null, 2)}</pre>
                        <p className="mt-2 break-all text-xs">Lineage hash: {fact.source_lineage_hash}<br />Fingerprint: {fact.fingerprint}</p>
                    </details>
                </article>)}
            </section>
            <section aria-labelledby="source-quality" className="mt-7 rounded-2xl border border-white/15 p-5">
                <h2 id="source-quality" className="text-xl font-semibold">Source quality and reconciliation</h2>
                <p className="mt-3 text-neutral-300">Local receipt checks show missing projections, duplicates, conflicts and late arrivals. Expected totals stay unknown without an independently verified source checkpoint. Previous reports are preserved.</p>
                <form className="mt-4" onSubmit={(e) => { e.preventDefault(); post(`${p.actions.base}/quality`, { source, event_type: qualityType, start, end }); }}>
                    <div className="grid gap-4 sm:grid-cols-2"><label>Canonical source<input className={control} value={source} onChange={(e) => setSource(e.target.value)} maxLength={64} pattern="[a-zA-Z0-9._-]+" required /></label>
                        <label>Quality event type<select className={control} value={qualityType} onChange={(e) => setQualityType(e.target.value)}>{(p.quality_event_types ?? ['product.viewed']).map((k) => <option key={k}>{k}</option>)}</select></label></div>
                    <p className="mt-3 text-sm text-neutral-300">Uses the start and end dates above, in UTC.</p><button className={`${button} mt-4`} disabled={busy || unavailable || !source || start >= end}>Check source quality</button>
                </form>
                {(p.quality_reports ?? []).length === 0 && <p className="mt-4 text-neutral-300">No valid source quality checks in this scope.</p>}
                {(p.quality_reports ?? []).map((q) => <article key={q.id} className="mt-5 rounded-xl border border-white/15 p-4">
                    <h3 className="font-semibold">{q.event_type} · {q.coverage.replaceAll('_', ' ')}</h3><p className="mt-2 break-words text-sm">{q.start_utc} to {q.end_utc}</p>
                    <dl className="mt-3 grid grid-cols-2 gap-2 text-sm"><dt>Expected source total</dt><dd>{q.expected_total ?? 'unknown'}</dd>
                        <dt>Missing source keys</dt><dd>{q.missing_source_keys ?? 'unknown'}</dd><dt>Unexpected source keys</dt><dd>{q.unexpected_source_keys ?? 'unknown'}</dd>
                        <dt>Missing projections</dt><dd>{q.missing_projection}</dd><dt>Duplicate receipts</dt><dd>{q.duplicates}</dd><dt>Conflicts</dt><dd>{q.conflicts}</dd>
                        <dt>Late receipts</dt><dd>{q.late}</dd><dt>Drifted hashes</dt><dd>{q.drifted_hashes}</dd><dt>Maximum receipt lag (seconds)</dt><dd>{q.max_receipt_lag_seconds}</dd></dl>
                    <details className="mt-3"><summary className="cursor-pointer text-sky-200">Affected metric versions and evidence</summary>
                        {q.affected_metric_versions.map((m) => <p key={m.snapshot_id} className="mt-2 break-all text-xs">Snapshot {m.snapshot_id} · version {m.version} · definition {m.definition_hash}</p>)}
                        <p className="mt-2 break-all text-xs">Fingerprint: {q.fingerprint}<br />Source reference: {q.source_hash}</p></details>
                </article>)}
            </section>
            <section aria-labelledby="report-insights" className="mt-7 rounded-2xl border border-white/15 p-5"><h2 id="report-insights" className="text-xl font-semibold">Measured insight checks</h2>
                <label className="mt-4 block">Current snapshot<select className={control} value={selected} onChange={(e) => setSelected(e.target.value)}><option value="">Select a snapshot</option>{p.reports.map((r) => <option key={r.id} value={r.id}>{r.start_utc} · {String(r.definition.kind ?? r.definition.event_type)} · {r.id}</option>)}</select></label>
                <fieldset className="mt-4"><legend>Prior daily count snapshots (7–14 comparable periods)</legend><div className="mt-2 grid gap-2">{p.reports.filter((r) => r.id !== selected && 'count' in r.metrics).map((r) => <label key={r.id} className="break-all text-sm"><input className="mr-2 focus-visible:outline-2 focus-visible:outline-sky-300" type="checkbox" checked={baseline.includes(r.id)} onChange={(e) => setBaseline(e.target.checked ? [...baseline, r.id] : baseline.filter((id) => id !== r.id))} />{r.start_utc} · {r.id}</label>)}</div></fieldset>
                <div className="mt-4 flex flex-wrap gap-3"><button className={button} disabled={busy || unavailable || !selected} onClick={() => post(`${p.actions.base}/anomaly`, { snapshot_id: selected, baseline })}>Check local anomaly baseline</button>
                    <button className={button} disabled={busy || unavailable || !selected || !p.explanation_available} onClick={() => post(`${p.actions.base}/explain`, { snapshot_id: selected })}>Request validated explanation</button></div>
                {!p.explanation_available && <p className="mt-3 text-neutral-300">No approved explanation route is configured.</p>}
                <p className="mt-3 text-sm text-neutral-300">Local signals are descriptive, not calibrated probability or proof of cause. Incomplete, stale or incomparable baselines cannot produce a confident anomaly.</p>
                {p.insight && <p role="status" className="mt-3 text-sky-200">Insight status: {p.insight.status ?? 'validated'}. {p.insight.baseline_n !== undefined && `Baseline periods: ${p.insight.baseline_n}.`} {p.insight.z_score !== undefined && `Local z-score: ${p.insight.z_score.toFixed(3)}.`}</p>}
                {p.insight?.output && <div className="mt-4"><h3 className="font-semibold">Validated explanation</h3><ul className="mt-2 list-inside list-disc">{p.insight.output.facts.map((fact) => <li key={fact.metric}>Measured {fact.metric}: {fact.value}.</li>)}{p.insight.output.inferences.map((inference) => <li key={inference}>Inference: {inference.replaceAll('_', ' ')}.</li>)}</ul><p className="mt-2 text-sm text-neutral-300">R0 only; measured values reference the selected immutable snapshot. Inferences are unproven.</p></div>}
            </section>
            <section aria-labelledby="report-schedules" className="mt-7"><h2 id="report-schedules" className="text-xl font-semibold">Your daily schedules</h2>
                {p.schedules.length === 0 ? <p className="mt-3 text-neutral-300">No schedules owned by you in this scope.</p> : <ul className="mt-4 grid gap-3">{p.schedules.map((s) => <li key={s.id} className="rounded-xl border border-white/15 p-4"><p className="break-words">{s.kind} · {s.enabled ? 'enabled' : 'disabled'} · next UTC window end: {s.next_window_end}</p>{s.status_code && <p className="mt-2 text-amber-200">Schedule status: {s.status_code}</p>}
                    <button className={`${button} mt-3`} disabled={busy || unavailable || !s.enabled} onClick={() => post(`${p.actions.base}/schedules/${s.id}/disable`, {})}>Disable schedule</button></li>)}</ul>}
            </section>
        </div></main></>;
}
