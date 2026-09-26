import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import RuleBuilder from './rule-builder';

type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue };
type Field = { id: string; type: string; operators: string[] };
type Proposal = {
    status: string;
    code?: string;
    questions?: string[];
    definition?: Record<string, unknown>;
    definition_hash?: string;
    explanation?: string;
    path?: string;
};
type SavedSegment = { id: string; version: number; hash: string; published?: boolean };
type ExistingSegment = { id: string; name: string; status: string; published_version: number | null;
    latest_version: number; latest_hash: string; latest_definition: Record<string, JsonValue> };
type Preview = {
    status: string; count_kind: string; count: number | null; count_lower_bound?: number | null;
    definition_hash: string; definition_version: number | null; evaluated_at: string;
    source_freshness_at: string | null; eligibility_explanation: string;
};
type Props = {
    proposal_available: boolean;
    fields: Field[];
    registered_events: string[];
    proposal_result: Proposal | null;
    saved_segment: SavedSegment | null;
    segments: ExistingSegment[];
    preview_result: Preview | null;
    actions: { propose: string; store: string; preview: string; revise_base: string };
};

const notices: Record<string, string> = {
    unavailable: 'Natural-language proposals are unavailable because no approved AI route is configured. You can still edit a structured definition.',
    invalid_input: 'Enter a supported request without personal email addresses or provider credentials.',
    input_rejected: 'Remove personal email addresses or secret values before requesting a proposal.',
    invalid: 'The proposed definition failed deterministic validation. It has not been saved.',
    failed: 'The proposal could not be validated. No segment was saved.',
    permission_denied: 'Your workspace permissions do not allow this operation.',
    clarification_required: 'The request needs clarification before it can become a segment.',
};

export default function SegmentationOperator({
    proposal_available,
    fields,
    registered_events,
    proposal_result,
    saved_segment,
    segments,
    preview_result,
    actions,
}: Props) {
    const [intent, setIntent] = useState('');
    const [name, setName] = useState('');
    const [definitionText, setDefinitionText] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [previewedDefinition, setPreviewedDefinition] = useState<string | null>(null);
    const [selectedSegment, setSelectedSegment] = useState<ExistingSegment | null>(null);
    const result = proposal_result;

    useEffect(() => {
        if (result?.status === 'proposed' && result.definition) {
            setDefinitionText(JSON.stringify(result.definition, null, 2));
            setConfirmed(false);
        }
    }, [result]);
    const definition = useMemo(() => {
        if (definitionText.trim() === '') return null;
        try {
            const value: unknown = JSON.parse(definitionText);
            return value !== null && typeof value === 'object' && !Array.isArray(value)
                ? value as Record<string, JsonValue>
                : null;
        } catch {
            return null;
        }
    }, [definitionText]);

    const propose = () => {
        if (busy || intent.trim() === '') return;
        setBusy(true);
        router.post(actions.propose, { intent: intent.trim() }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const save = () => {
        if (busy || !definition || name.trim() === '' || !confirmed) return;
        setBusy(true);
        router.post(selectedSegment ? `${actions.revise_base}/${selectedSegment.id}/versions` : actions.store, {
            name: name.trim(),
            definition,
            confirmed: true,
        }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const preview = () => {
        if (busy || !definition) return;
        setPreviewedDefinition(definitionText);
        setBusy(true);
        const selectedUnchanged = selectedSegment !== null
            && definitionText === JSON.stringify(selectedSegment.latest_definition, null, 2);
        router.post(actions.preview, {
            definition,
            ...(selectedUnchanged ? { segment_id: selectedSegment.id, version: selectedSegment.latest_version } : {}),
        }, {
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };
    const publish = () => {
        const target = selectedSegment && saved_segment?.id !== selectedSegment.id ? {
            id: selectedSegment.id, version: selectedSegment.latest_version, hash: selectedSegment.latest_hash,
        } : (saved_segment ?? (selectedSegment ? {
            id: selectedSegment.id, version: selectedSegment.latest_version, hash: selectedSegment.latest_hash,
        } : null));
        if (!target || busy || !window.confirm(`Publish immutable segment version ${target.version}?`)) return;
        setBusy(true);
        router.post(`${actions.revise_base}/${target.id}/publish`, { version: target.version, confirmed: true }, {
            preserveScroll: true, onFinish: () => setBusy(false),
        });
    };

    const statusMessage = result
        ? notices[result.status] ?? (result.status === 'proposed'
            ? 'Proposal ready for review. Check and edit the structured definition before saving.'
            : 'The request was not saved.')
        : proposal_available
          ? 'A proposal is created only after deterministic validation.'
          : notices.unavailable;
    const visualRoot = definition?.root;

    return (
        <>
            <Head title="Audience segments" />
            <main className="min-h-screen bg-[radial-gradient(circle_at_top,#172033_0%,#0a0a0a_32%,#050505_100%)] text-neutral-100">
                <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
                    <header>
                        <p className="text-xs font-semibold uppercase tracking-[0.22em] text-sky-300">VSN Marketing · Audience</p>
                        <h1 className="mt-3 text-3xl font-semibold tracking-tight text-white">Segment builder</h1>
                        <p className="mt-2 max-w-3xl text-sm leading-6 text-neutral-400">
                            Build a workspace-scoped contact audience from registered fields and events. Natural-language output is only a proposal; server validation determines what can be saved.
                        </p>
                    </header>

                    <section className="mt-7 rounded-3xl border border-white/10 bg-white/[0.035] p-5 sm:p-6" aria-labelledby="intent-heading">
                        <h2 id="intent-heading" className="text-lg font-semibold text-white">Describe an audience</h2>
                        <p id="intent-help" className="mt-1 text-sm leading-6 text-neutral-400">
                            Do not include customer names, email addresses, or secrets. Ambiguous terms such as “high value” need a defined rule.
                        </p>
                        <label htmlFor="segment-intent" className="mt-4 block text-sm font-medium text-neutral-200">Audience request</label>
                        <textarea
                            id="segment-intent"
                            value={intent}
                            onChange={(event) => setIntent(event.target.value)}
                            maxLength={2000}
                            aria-describedby="intent-help intent-limit"
                            rows={4}
                            className="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-3 text-sm text-white outline-none focus:border-sky-400/50"
                            placeholder="For example: contacts created in the last 30 days"
                        />
                        <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p id="intent-limit" className="text-xs text-neutral-500">{intent.length} of 2000 characters</p>
                            <button
                                type="button"
                                onClick={propose}
                                disabled={!proposal_available || intent.trim() === '' || busy}
                                className="rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-black disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                {busy ? 'Working…' : 'Propose rules'}
                            </button>
                        </div>
                    </section>

                    <section className="mt-5 rounded-2xl border border-white/10 p-4" aria-labelledby="existing-segments">
                        <h2 id="existing-segments" className="text-lg font-semibold">Saved segments</h2>
                        {segments.length === 0 ? <p className="mt-2 text-sm text-neutral-400">No saved segments in this workspace.</p> :
                            <ul className="mt-3 space-y-2">{segments.map((segment) => <li key={segment.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-white/10 p-3 text-sm">
                                <span>{segment.name} · latest v{segment.latest_version} · published {segment.published_version ?? 'none'}</span>
                                <button type="button" onClick={() => {
                                    setSelectedSegment(segment); setName(segment.name);
                                    setDefinitionText(JSON.stringify(segment.latest_definition, null, 2)); setConfirmed(false);
                                }} className="rounded border border-white/20 px-3 py-2">Edit a new version</button>
                            </li>)}</ul>}
                    </section>

                    <section className="mt-4 rounded-2xl border border-white/10 bg-black/20 p-4" aria-live="polite" aria-atomic="true">
                        <p className="text-sm text-neutral-200">{statusMessage}</p>
                        {result?.code && <p className="mt-1 text-xs text-neutral-500">Status: {result.code.replaceAll('_', ' ')}</p>}
                        {result?.questions && result.questions.length > 0 && (
                            <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-100">
                                {result.questions.map((question, index) => <li key={index}>{question}</li>)}
                            </ul>
                        )}
                    </section>

                    <section className="mt-6 rounded-3xl border border-white/10 bg-white/[0.035] p-5 sm:p-6" aria-labelledby="review-heading">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 id="review-heading" className="text-lg font-semibold text-white">Review structured rules</h2>
                                <p className="mt-1 text-sm leading-6 text-neutral-400">
                                    Edit the canonical JSON before saving. SQL, custom fields, event properties, and list or tag IDs are unsupported.
                                </p>
                            </div>
                            {result?.definition_hash && <p className="font-mono text-xs text-neutral-500">Definition {result.definition_hash.slice(0, 12)}</p>}
                        </div>

                        {result?.explanation && (
                            <pre className="mt-4 overflow-x-auto whitespace-pre-wrap rounded-xl border border-sky-400/15 bg-sky-400/[0.04] p-4 text-sm leading-6 text-sky-100" aria-label="Deterministic rule explanation">
                                {result.explanation}
                            </pre>
                        )}

                        <label htmlFor="segment-name" className="mt-5 block text-sm font-medium text-neutral-200">Segment name</label>
                        <input
                            id="segment-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            maxLength={191}
                            className="mt-2 w-full rounded-xl border border-white/10 bg-black/25 px-3 py-2.5 text-sm text-white outline-none focus:border-sky-400/50"
                        />

                        <label htmlFor="segment-definition" className="mt-4 block text-sm font-medium text-neutral-200">Structured definition (JSON)</label>
                        <div className="mt-4 rounded-xl border border-white/10 p-4">
                            <h3 className="mb-2 text-sm font-semibold">Visual rules</h3>
                            {visualRoot && typeof visualRoot === 'object' && !Array.isArray(visualRoot) && visualRoot.type === 'group'
                                ? <RuleBuilder root={visualRoot as Record<string, unknown>} fields={fields} events={registered_events}
                                    onChange={(root) => { setDefinitionText(JSON.stringify({ schema_version: 1, subject: 'contact', root }, null, 2)); setConfirmed(false); }} />
                                : <button type="button" disabled={fields.length === 0} onClick={() => {
                                    setDefinitionText(JSON.stringify({ schema_version: 1, subject: 'contact', root: {
                                        type: 'group', operator: 'all', children: [{ type: 'attribute', field: fields[0].id, operator: 'is_set' }],
                                    } }, null, 2)); setConfirmed(false);
                                }} className="rounded border border-white/20 px-3 py-2 text-sm disabled:opacity-40">Start visual rule builder</button>}
                        </div>
                        <textarea
                            id="segment-definition"
                            value={definitionText}
                            onChange={(event) => {
                                setDefinitionText(event.target.value);
                                setConfirmed(false);
                            }}
                            rows={12}
                            spellCheck={false}
                            aria-describedby="definition-help"
                            className="mt-2 w-full rounded-xl border border-white/10 bg-black/35 px-3 py-3 font-mono text-xs leading-5 text-neutral-200 outline-none focus:border-sky-400/50"
                            placeholder={'{"schema_version":1,"subject":"contact","root":{"type":"group","operator":"all","children":[...]}}'}
                        />
                        <p id="definition-help" className="mt-2 text-xs leading-5 text-neutral-500">
                            Registered fields: {fields.map((field) => field.id).join(', ') || 'none'}.
                            {' '}Workspace events: {registered_events.join(', ') || 'none'}.
                            {' '}Allowed meaning comes from registered node and operator types; the server validates every save.
                        </p>
                        {!definition && definitionText.trim() !== '' && (
                            <p className="mt-2 text-sm text-rose-200" role="alert">Enter a valid JSON object.</p>
                        )}
                
                        <label className="mt-5 flex items-start gap-3 rounded-xl border border-amber-400/20 bg-amber-400/[0.04] p-3 text-sm leading-6 text-neutral-200">
                            <input
                                type="checkbox"
                                checked={confirmed}
                                onChange={(event) => setConfirmed(event.target.checked)}
                                className="mt-1 h-4 w-4 rounded border-white/20 bg-black"
                            />
                            <span>I reviewed the structured rules and confirm saving a new immutable draft version.</span>
                        </label>
                        <div className="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-xs text-neutral-500">The reviewed definition becomes an immutable draft version; the original request is not retained.</p>
                            <button type="button" onClick={preview} disabled={!definition || busy}
                                className="rounded-xl border border-sky-400/30 px-4 py-2.5 text-sm font-semibold text-sky-100 disabled:opacity-40">
                                {busy ? 'Evaluating…' : 'Preview bounded count'}
                            </button>
                            <button
                                type="button"
                                onClick={save}
                                disabled={!definition || name.trim() === '' || !confirmed || busy}
                                className="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-2.5 text-sm font-semibold text-emerald-100 disabled:cursor-not-allowed disabled:opacity-40"
                            >
                                {busy ? 'Saving…' : selectedSegment ? 'Confirm and save new version' : 'Confirm and save draft'}
                            </button>
                        </div>
                    </section>

                    {preview_result && (
                        <section className="mt-4 rounded-2xl border border-sky-400/20 bg-sky-400/[0.05] p-4" aria-live="polite" aria-label="Audience preview">
                            <h2 className="font-semibold text-sky-100">Audience count · {preview_result.status.replaceAll('_', ' ')}</h2>
                            {previewedDefinition !== definitionText && <p className="mt-2 text-sm text-amber-100" role="status">Rules have changed. This count is stale; preview again.</p>}
                            <p className="mt-2 text-sm text-neutral-200">
                                {preview_result.count_kind === 'capped'
                                    ? `At least ${preview_result.count_lower_bound} contacts; exact count unavailable within the bounded probe.`
                                    : preview_result.count_kind === 'exact'
                                      ? `${preview_result.count} contacts · exact at evaluation time`
                                      : 'Count unavailable. Try again later or simplify the rules.'}
                            </p>
                            <p className="mt-2 text-xs text-neutral-400">Evaluated {preview_result.evaluated_at} · definition {preview_result.definition_hash.slice(0, 12)}
                                {preview_result.definition_version !== null ? ` · version ${preview_result.definition_version}` : ' · unsaved draft'}
                            </p>
                            <p className="mt-1 text-xs text-amber-100">Source freshness is unknown; this count can become stale after data changes.</p>
                            <p className="mt-2 text-xs text-neutral-300">{preview_result.eligibility_explanation}</p>
                            <p className="mt-1 text-xs text-neutral-400">Member identities and personal details are hidden in this preview.</p>
                        </section>
                    )}

                    {saved_segment && (
                        <section className="mt-4 rounded-2xl border border-emerald-400/20 bg-emerald-400/[0.05] p-4" role="status">
                            <h2 className="font-semibold text-emerald-100">{saved_segment.published ? 'Version published' : 'Draft version saved'}</h2>
                            <p className="mt-1 text-sm text-neutral-300">Version {saved_segment.version} · hash {saved_segment.hash}</p>
                        </section>
                    )}
                    {(saved_segment || selectedSegment) && <button type="button" onClick={publish} disabled={busy}
                        className="mt-3 rounded-xl border border-amber-400/30 px-4 py-2 text-sm text-amber-100 disabled:opacity-40">
                        Publish selected immutable version
                    </button>}

                    <details className="mt-6 rounded-2xl border border-white/10 bg-black/20 p-4">
                        <summary className="cursor-pointer text-sm font-medium text-neutral-200">Supported field and event metadata</summary>
                        <ul className="mt-3 grid gap-2 text-xs text-neutral-400 sm:grid-cols-2">
                            {fields.map((field) => (
                                <li key={field.id} className="rounded-lg border border-white/10 p-3">
                                    <span className="font-mono text-neutral-200">{field.id}</span> · {field.type}
                                    <span className="mt-1 block">Operators: {field.operators.join(', ')}</span>
                                </li>
                            ))}
                            {registered_events.map((eventName) => <li key={eventName} className="rounded-lg border border-white/10 p-3 font-mono">{eventName}</li>)}
                        </ul>
                    </details>
                </div>
            </main>
        </>
    );
}
