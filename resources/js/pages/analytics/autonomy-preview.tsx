type OfflineAction = {
    tool_id: string;
    effect: 'read' | 'proposal';
    risk: 'R0' | 'R1';
    arguments_sha256: string;
    source_ids: string[];
    reason_code: string;
};

export type OfflineAutonomyPreview = {
    status: string;
    execution_authorized: boolean;
    run_id: string;
    policy_version: string;
    snapshot_sha256: string;
    actions: OfflineAction[];
    stages: {
        goal: string;
        plan: string;
        propose: string;
        execute: string;
        observe: string;
        evaluate: string;
    };
};

type Props = { preview?: OfflineAutonomyPreview | null };

export default function OfflineAutonomyPreviewPanel({ preview }: Props) {
    const valid = preview !== undefined && preview !== null
        && preview.status === 'preview_ready'
        && preview.execution_authorized === false
        && preview.stages.execute === 'disabled'
        && preview.stages.propose === 'offline_preview'
        && Array.isArray(preview.actions)
        && preview.actions.every((a) => (a.effect === 'read' || a.effect === 'proposal') && (a.risk === 'R0' || a.risk === 'R1'));

    return <section className="rounded-2xl border border-white/15 p-5" aria-labelledby="offline-autonomy-heading">
        <h2 id="offline-autonomy-heading" className="text-xl font-semibold">Bounded AI marketing preview</h2>
        <p className="mt-2 text-sm text-neutral-300">Offline, proposal-only review. AI-generated content does not grant consent, external execution, campaign promotion or billing authority.</p>
        {!valid ? <p role="status" className="mt-4 text-amber-200">No validated offline proposal is available. All autonomous actions remain disabled.</p> : <>
            <dl className="mt-4 grid gap-3 sm:grid-cols-2">
                <div><dt className="text-sm text-neutral-300">Run identifier</dt><dd className="break-all font-medium">{preview.run_id}</dd></div>
                <div><dt className="text-sm text-neutral-300">Policy revision</dt><dd className="break-all font-medium">{preview.policy_version}</dd></div>
                <div><dt className="text-sm text-neutral-300">Execution state</dt><dd className="font-semibold text-amber-200">Disabled — offline preview only</dd></div>
                <div><dt className="text-sm text-neutral-300">Observation and evaluation</dt><dd className="font-medium">Not independently certified</dd></div>
            </dl>
            <div className="mt-4 overflow-x-auto" role="region" aria-label="Offline proposed actions" tabIndex={0}>
                <table className="w-full text-left text-sm">
                    <caption className="mb-2 text-left text-neutral-300">Proposed read-only actions; none are authorized to run</caption>
                    <thead><tr><th scope="col" className="p-2">Tool</th><th scope="col" className="p-2">Effect</th><th scope="col" className="p-2">Risk</th><th scope="col" className="p-2">Evidence sources</th></tr></thead>
                    <tbody>{preview.actions.map((a, i) => <tr key={a.tool_id + ':' + i}>
                        <th scope="row" className="border-t border-white/10 p-2 font-medium">{a.tool_id}</th>
                        <td className="border-t border-white/10 p-2">{a.effect}</td>
                        <td className="border-t border-white/10 p-2">{a.risk}</td>
                        <td className="border-t border-white/10 p-2">{a.source_ids.join(', ')}</td>
                    </tr>)}</tbody>
                </table>
            </div>
            <details className="mt-4"><summary className="cursor-pointer text-sky-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sky-300">View proposal fingerprint</summary>
                <p className="mt-2 break-all font-mono text-xs">{preview.snapshot_sha256}</p>
            </details>
        </>}
        <div className="mt-5 flex flex-wrap gap-3">
            <button type="button" disabled className="rounded-xl bg-neutral-700 px-4 py-2 text-white disabled:opacity-50">Execute actions (unavailable)</button>
            <button type="button" disabled className="rounded-xl border border-white/20 px-4 py-2 disabled:opacity-50">Promote campaign (unavailable)</button>
        </div>
        <p className="mt-3 text-sm text-neutral-300">Already-sent provider actions cannot be undone. This preview never sends anything.</p>
    </section>;
}
