import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type Workspace = {
    id: string;
    name: string;
    slug: string;
};

type Snapshot = {
    id: string;
    version_number: number;
    content_version_id: string;
    snapshot_hash: string;
    target_set_hash: string;
    target_count: number;
    channels: string[];
    intended_execution: Record<string, string | number | boolean>;
    created_at: string;
};

type Approval = {
    outcome: string;
    actor_role: string;
    occurred_at: string;
    expires_at: string | null;
    revoked: boolean;
};

type Schedule = {
    strategy: string;
    timezone_id: string;
    local_scheduled_at: string;
    resolved_at_utc: string;
};

type ProviderOutcome = {
    status: string;
    action: string | null;
    retry_blocked: boolean;
    retry_after_seconds: number | null;
    next_probe_at: string | null;
    evidence: string;
};

type PublicationTarget = {
    target_id: string;
    channel: string;
    state: string;
    retry_eligible: boolean;
    provider: ProviderOutcome | null;
};

type Publication = {
    execution_intent_id: string | null;
    state: string;
    retry_eligible_count: number;
    counts: {
        total: number;
        succeeded: number;
        pending: number;
        in_progress: number;
        failed: number;
        cancelled: number;
    };
    targets: PublicationTarget[];
};

type Campaign = {
    id: string;
    name: string;
    status: string;
    state_version: number;
    snapshot: Snapshot | null;
    approval: Approval | null;
    schedule: Schedule | null;
    publication: Publication | null;
};

type Summary = {
    campaigns: number;
    needs_approval: number;
    scheduled: number;
    partial_success: number;
    provider_attention: number;
};

type BulkResult = {
    batch_id: string;
    operation: 'approve' | 'reject';
    confirmed: boolean;
    role_source: string;
    counts: {
        total: number;
        eligible: number;
        applied: number;
        already_applied: number;
        ineligible: number;
        conflict: number;
    };
    results: Array<{
        campaign_id: string;
        snapshot_id: string;
        expected_state_version: number;
        status: string;
        reason: string | null;
    }>;
};

type Props = {
    workspace: Workspace;
    campaigns: Campaign[];
    summary: Summary;
    permissions: {
        can_approve: boolean;
    };
    bulk_result: BulkResult | null;
};

const stateLabels: Record<string, string> = {
    not_started: 'Not started',
    pending: 'Pending',
    in_progress: 'In progress',
    partial_success: 'Partial success',
    succeeded: 'Succeeded',
    failed_retriable: 'Retry eligible',
    failed_terminal: 'Failed',
    failed_unclassified: 'Needs review',
    cancelled: 'Cancelled',
    draft: 'Draft',
    needs_approval: 'Needs approval',
    approved: 'Approved',
    ready: 'Ready',
    scheduled_intent: 'Scheduled',
    running: 'Running',
    completed: 'Completed',
    provider_disconnected: 'Provider disconnected',
    credential_invalid: 'Credential needs attention',
    permission_lost: 'Provider permission lost',
    app_review_restricted: 'Provider app review required',
    capability_drift: 'Provider capability changed',
    provider_authority_stale: 'Provider authority stale',
    circuit_open: 'Provider circuit open',
    circuit_half_open: 'Provider recovery probe active',
    rate_limited: 'Provider rate limited',
};

function label(value: string): string {
    return stateLabels[value] ?? value.replaceAll('_', ' ');
}

function badgeClass(value: string): string {
    if (value === 'succeeded' || value === 'approved' || value === 'completed') {
        return 'border-emerald-400/20 bg-emerald-400/10 text-emerald-200';
    }

    if (value === 'partial_success' || value === 'needs_approval' || value === 'failed_retriable') {
        return 'border-amber-400/20 bg-amber-400/10 text-amber-100';
    }

    if (value === 'failed_terminal' || value === 'failed_unclassified' || value === 'cancelled') {
        return 'border-rose-400/20 bg-rose-400/10 text-rose-100';
    }

    return 'border-white/10 bg-white/5 text-neutral-300';
}

function Badge({ value }: { value: string }) {
    return (
        <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-medium capitalize ${badgeClass(value)}`}>
            {label(value)}
        </span>
    );
}

const providerActionLabels: Record<string, string> = {
    reconnect_provider: 'Reconnect the provider connection',
    reauthenticate_provider: 'Reauthenticate the provider connection',
    reauthorize_permissions: 'Reauthorize required provider permissions',
    complete_provider_review: 'Complete the provider app-review requirement',
    refresh_provider_capability: 'Refresh provider capability evidence',
    refresh_provider_authority: 'Refresh provider authority evidence',
    wait_for_provider_probe: 'Wait for the provider recovery probe',
    wait_for_rate_reset: 'Wait for the provider rate-limit reset',
};

function ProviderNotice({ provider }: { provider: ProviderOutcome }) {
    if (provider.status === 'ready') return null;

    const timing = provider.retry_after_seconds !== null
        ? `Retry window in about ${provider.retry_after_seconds} seconds.`
        : provider.next_probe_at
          ? `Next safe probe: ${formatDate(provider.next_probe_at)}.`
          : null;

    return (
        <div
            className="mt-2 rounded-lg border border-amber-400/20 bg-amber-400/[0.06] px-3 py-2.5"
            role="status"
            aria-label={`Provider attention: ${label(provider.status)}`}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-semibold text-amber-100">{label(provider.status)}</span>
                {provider.retry_blocked && (
                    <span className="rounded-full border border-amber-400/20 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-amber-200">
                        Retry blocked
                    </span>
                )}
            </div>
            {provider.action && (
                <p className="mt-1 text-xs leading-5 text-neutral-400">
                    Next safe step: {providerActionLabels[provider.action] ?? label(provider.action)}.
                </p>
            )}
            {timing && <p className="mt-1 text-xs text-neutral-500">{timing}</p>}
        </div>
    );
}

function shortHash(value: string): string {
    return value.length > 14 ? `${value.slice(0, 8)}…${value.slice(-6)}` : value;
}

function formatDate(value: string | null): string {
    if (!value) return '—';

    const date = new Date(value);
    return Number.isNaN(date.valueOf())
        ? value
        : new Intl.DateTimeFormat('en', {
              dateStyle: 'medium',
              timeStyle: 'short',
              timeZone: 'UTC',
          }).format(date) + ' UTC';
}

function newBatchId(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function') {
        return globalThis.crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (token) => {
        const value = Math.floor(Math.random() * 16);
        const nibble = token === 'x' ? value : (value & 0x3) | 0x8;

        return nibble.toString(16);
    });
}

function StatCard({ label: title, value, hint }: { label: string; value: number; hint: string }) {
    return (
        <div className="rounded-2xl border border-white/10 bg-white/[0.035] p-4">
            <p className="text-xs font-medium uppercase tracking-[0.16em] text-neutral-500">{title}</p>
            <p className="mt-2 text-3xl font-semibold tracking-tight text-white">{value}</p>
            <p className="mt-1 text-sm text-neutral-500">{hint}</p>
        </div>
    );
}

function EmptyState() {
    return (
        <div className="rounded-3xl border border-dashed border-white/10 bg-white/[0.02] px-6 py-14 text-center">
            <p className="text-sm font-medium text-neutral-200">No campaigns in this workspace yet.</p>
            <p className="mx-auto mt-2 max-w-lg text-sm leading-6 text-neutral-500">
                This operator surface reads canonical campaign state. Approval mutations are exposed only through guarded server-authorized workflows.
            </p>
        </div>
    );
}


function ApprovalQueue({
    workspace,
    campaigns,
    canApprove,
    bulkResult,
}: {
    workspace: Workspace;
    campaigns: Campaign[];
    canApprove: boolean;
    bulkResult: BulkResult | null;
}) {
    const candidates = useMemo(
        () => campaigns.filter((campaign) => campaign.status === 'needs_approval' && campaign.snapshot !== null),
        [campaigns],
    );
    const [selected, setSelected] = useState<string[]>([]);
    const [reason, setReason] = useState('');
    const [batchId, setBatchId] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState<'approve' | 'reject' | null>(null);

    const selectedItems = candidates
        .filter((campaign) => selected.includes(campaign.id))
        .map((campaign) => ({
            campaign_id: campaign.id,
            snapshot_id: campaign.snapshot!.id,
            state_version: campaign.state_version,
        }));
    const pendingPreflight = bulkResult && !bulkResult.confirmed ? bulkResult : null;
    const isBusy = submitting !== null;

    const toggle = (campaignId: string) => {
        setSelected((current) =>
            current.includes(campaignId)
                ? current.filter((id) => id !== campaignId)
                : [...current, campaignId],
        );
    };

    const submit = (operation: 'approve' | 'reject', confirmed: boolean) => {
        if (isBusy || (operation === 'reject' && reason.trim().length < 3)) return;

        const items = confirmed && pendingPreflight
            ? pendingPreflight.results.map((result) => ({
                  campaign_id: result.campaign_id,
                  snapshot_id: result.snapshot_id,
                  state_version: result.expected_state_version,
              }))
            : selectedItems;

        if (items.length === 0) return;

        const nextBatchId = confirmed
            ? pendingPreflight?.batch_id ?? batchId
            : newBatchId();

        if (!nextBatchId) return;
        if (!confirmed) setBatchId(nextBatchId);

        setSubmitting(operation);
        router.post(
            `/workspaces/${workspace.id}/publishing/approvals/bulk`,
            {
                batch_id: nextBatchId,
                operation,
                confirmed,
                reason: reason.trim() === '' ? null : reason.trim(),
                items,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSubmitting(null),
            },
        );
    };

    if (!canApprove || candidates.length === 0) {
        return null;
    }

    return (
        <section
            className="mt-8 rounded-3xl border border-amber-400/15 bg-amber-400/[0.035] p-5"
            aria-label="Approval queue"
            aria-busy={isBusy}
        >
            <p className="sr-only" aria-live="polite">
                {isBusy ? `Submitting ${submitting} decision…` : 'Approval controls ready.'}
            </p>
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.18em] text-amber-200">Approval queue</p>
                    <h2 className="mt-2 text-xl font-semibold text-white">Guarded bulk decisions</h2>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-neutral-400">
                        Selection is only a request. The server rechecks workspace authority, current approver role, state version and immutable snapshot before any decision is recorded.
                    </p>
                </div>
                <div className="rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-sm text-neutral-300">
                    {selected.length} selected · max 25
                </div>
            </div>

            <div className="mt-5 grid gap-2">
                {candidates.map((campaign) => (
                    <label key={campaign.id} className="flex cursor-pointer items-center gap-3 rounded-xl border border-white/10 bg-black/15 px-3 py-3">
                        <input
                            type="checkbox"
                            checked={selected.includes(campaign.id)}
                            disabled={Boolean(pendingPreflight) || isBusy}
                            onChange={() => toggle(campaign.id)}
                            className="h-4 w-4 rounded border-white/20 bg-black disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-neutral-100">{campaign.name}</p>
                            <p className="mt-1 font-mono text-[11px] text-neutral-600">
                                snapshot {shortHash(campaign.snapshot!.id)} · state v{campaign.state_version}
                            </p>
                        </div>
                        <Badge value={campaign.status} />
                    </label>
                ))}
            </div>

            <div className="mt-4 grid gap-3 lg:grid-cols-[1fr_auto]">
                <label className="block">
                    <span className="text-xs text-neutral-500">Decision reason · required for rejection</span>
                    <input
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        maxLength={500}
                        disabled={Boolean(pendingPreflight) || isBusy}
                        className="mt-1.5 w-full rounded-xl border border-white/10 bg-black/20 px-3 py-2.5 text-sm text-neutral-100 outline-none placeholder:text-neutral-700 focus:border-sky-400/40"
                        placeholder="Why is this decision being made?"
                    />
                </label>
                <div className="flex items-end gap-2">
                    <button
                        type="button"
                        disabled={selected.length === 0 || Boolean(pendingPreflight) || isBusy}
                        onClick={() => submit('approve', false)}
                        className="rounded-xl border border-emerald-400/20 bg-emerald-400/10 px-4 py-2.5 text-sm font-medium text-emerald-100 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Preflight approve
                    </button>
                    <button
                        type="button"
                        disabled={selected.length === 0 || reason.trim().length < 3 || Boolean(pendingPreflight) || isBusy}
                        onClick={() => submit('reject', false)}
                        className="rounded-xl border border-rose-400/20 bg-rose-400/10 px-4 py-2.5 text-sm font-medium text-rose-100 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                        Preflight reject
                    </button>
                </div>
            </div>

            {bulkResult && (
                <div className="mt-5 rounded-2xl border border-white/10 bg-black/20 p-4" role="status" aria-live="polite">
                    {bulkResult.counts.conflict > 0 && (
                        <div className="mb-3 rounded-xl border border-rose-400/20 bg-rose-400/[0.06] px-3 py-2 text-sm text-rose-100" role="alert">
                            {bulkResult.counts.conflict} decision {bulkResult.counts.conflict === 1 ? 'conflict was' : 'conflicts were'} blocked because canonical campaign state changed. Refresh before retrying.
                        </div>
                    )}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-sm font-medium text-white">
                                {bulkResult.confirmed ? 'Execution result' : 'Preflight result'} · {label(bulkResult.operation)}
                            </p>
                            <p className="mt-1 text-xs text-neutral-500">
                                {bulkResult.counts.eligible} eligible · {bulkResult.counts.applied} applied · {bulkResult.counts.conflict} conflicts · {bulkResult.counts.ineligible} ineligible
                            </p>
                        </div>
                        {!bulkResult.confirmed && bulkResult.counts.eligible > 0 && (
                            <div className="flex flex-col items-stretch gap-2 sm:items-end">
                                {bulkResult.operation === 'reject' && (
                                    <p id="bulk-reject-warning" className="max-w-xs text-xs leading-5 text-rose-200">
                                        Reject is destructive for this immutable approval decision. The server will recheck authority and state before recording it.
                                    </p>
                                )}
                                <button
                                    type="button"
                                    disabled={isBusy}
                                    aria-describedby={bulkResult.operation === 'reject' ? 'bulk-reject-warning' : undefined}
                                    onClick={() => submit(bulkResult.operation, true)}
                                    className={bulkResult.operation === 'reject'
                                        ? 'rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-2.5 text-sm font-semibold text-rose-100 disabled:cursor-not-allowed disabled:opacity-40'
                                        : 'rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-black disabled:cursor-not-allowed disabled:opacity-40'}
                                >
                                    {isBusy && submitting === bulkResult.operation ? 'Submitting…' : `Confirm ${bulkResult.operation}`}
                                </button>
                            </div>
                        )}
                    </div>
                    <div className="mt-3 grid gap-2">
                        {bulkResult.results.map((result) => (
                            <div key={result.campaign_id} className="flex items-center justify-between gap-3 rounded-lg border border-white/10 px-3 py-2 text-xs">
                                <span className="font-mono text-neutral-400">{shortHash(result.campaign_id)}</span>
                                <span className="text-neutral-200">{result.status}{result.reason ? ` · ${label(result.reason)}` : ''}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </section>
    );
}

function CampaignCard({ campaign }: { campaign: Campaign }) {
    const snapshot = campaign.snapshot;
    const publication = campaign.publication;

    return (
        <article className="overflow-hidden rounded-3xl border border-white/10 bg-neutral-900/70 shadow-2xl shadow-black/10">
            <header className="flex flex-col gap-4 border-b border-white/10 px-5 py-5 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="truncate text-lg font-semibold text-white">{campaign.name}</h2>
                        <Badge value={campaign.status} />
                    </div>
                    <p className="mt-2 font-mono text-xs text-neutral-600">
                        {campaign.id} · state v{campaign.state_version}
                    </p>
                </div>
                {publication && <Badge value={publication.state} />}
            </header>

            <div className="grid gap-0 lg:grid-cols-[1.15fr_0.85fr]">
                <section className="border-b border-white/10 p-5 lg:border-r lg:border-b-0">
                    <div className="flex items-center justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-500">Canonical preview</p>
                            <p className="mt-1 text-sm text-neutral-400">Immutable snapshot authority, not a transient provider render.</p>
                        </div>
                        {snapshot && <span className="rounded-lg bg-white/5 px-2.5 py-1 text-xs text-neutral-400">v{snapshot.version_number}</span>}
                    </div>

                    {snapshot ? (
                        <div className="mt-5 space-y-4">
                            <div className="rounded-2xl border border-white/10 bg-black/20 p-4">
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <dt className="text-xs text-neutral-500">Snapshot hash</dt>
                                        <dd className="mt-1 font-mono text-sm text-neutral-200" title={snapshot.snapshot_hash}>{shortHash(snapshot.snapshot_hash)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-neutral-500">Content version</dt>
                                        <dd className="mt-1 font-mono text-sm text-neutral-200">{shortHash(snapshot.content_version_id)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-neutral-500">Targets</dt>
                                        <dd className="mt-1 text-sm text-neutral-200">{snapshot.target_count}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-neutral-500">Snapshot created</dt>
                                        <dd className="mt-1 text-sm text-neutral-200">{formatDate(snapshot.created_at)}</dd>
                                    </div>
                                </dl>
                            </div>

                            <div>
                                <p className="text-xs text-neutral-500">Channel adaptations</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {snapshot.channels.map((channel) => (
                                        <span key={channel} className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs capitalize text-neutral-300">
                                            {channel}
                                        </span>
                                    ))}
                                </div>
                            </div>

                            {Object.keys(snapshot.intended_execution).length > 0 && (
                                <div className="rounded-2xl border border-white/10 bg-white/[0.025] p-4">
                                    <p className="text-xs text-neutral-500">Intended execution</p>
                                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                        {Object.entries(snapshot.intended_execution).map(([key, value]) => (
                                            <div key={key} className="flex justify-between gap-3 text-sm">
                                                <span className="capitalize text-neutral-500">{key.replaceAll('_', ' ')}</span>
                                                <span className="truncate text-right text-neutral-200">{String(value)}</span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <p className="mt-5 rounded-2xl border border-dashed border-white/10 p-5 text-sm text-neutral-500">
                            No immutable snapshot is registered for this campaign.
                        </p>
                    )}
                </section>

                <aside className="p-5">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-500">Governance</p>
                        <div className="mt-4 space-y-3">
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.025] px-3.5 py-3">
                                <span className="text-sm text-neutral-400">Approval</span>
                                {campaign.approval ? <Badge value={campaign.approval.outcome} /> : <span className="text-sm text-neutral-600">None</span>}
                            </div>
                            <div className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-white/[0.025] px-3.5 py-3">
                                <span className="text-sm text-neutral-400">Schedule</span>
                                <span className="text-right text-sm text-neutral-200">
                                    {campaign.schedule ? formatDate(campaign.schedule.resolved_at_utc) : 'Not scheduled'}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="mt-6">
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-neutral-500">Publication state</p>
                        {publication ? (
                            <>
                                <div className="mt-4 grid grid-cols-3 gap-2">
                                    <div className="rounded-xl bg-white/[0.035] p-3">
                                        <p className="text-xl font-semibold text-white">{publication.counts.succeeded}</p>
                                        <p className="mt-1 text-xs text-neutral-500">Succeeded</p>
                                    </div>
                                    <div className="rounded-xl bg-white/[0.035] p-3">
                                        <p className="text-xl font-semibold text-white">{publication.counts.pending + publication.counts.in_progress}</p>
                                        <p className="mt-1 text-xs text-neutral-500">Open</p>
                                    </div>
                                    <div className="rounded-xl bg-white/[0.035] p-3">
                                        <p className="text-xl font-semibold text-white">{publication.counts.failed}</p>
                                        <p className="mt-1 text-xs text-neutral-500">Failed</p>
                                    </div>
                                </div>

                                <div className="mt-4 space-y-2" aria-label="Publication targets">
                                    {publication.targets.map((target) => (
                                        <div key={target.target_id} className="rounded-xl border border-white/10 px-3 py-2.5">
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm capitalize text-neutral-200">{target.channel}</p>
                                                    <p className="mt-0.5 font-mono text-[11px] text-neutral-600">{shortHash(target.target_id)}</p>
                                                </div>
                                                <div className="flex flex-wrap items-center justify-end gap-2">
                                                    {target.retry_eligible && (
                                                        <span className="rounded-full border border-amber-400/20 px-2 py-1 text-[10px] font-medium uppercase tracking-wide text-amber-200">
                                                            eligible
                                                        </span>
                                                    )}
                                                    <Badge value={target.state} />
                                                </div>
                                            </div>
                                            {target.provider && <ProviderNotice provider={target.provider} />}
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <p className="mt-4 text-sm text-neutral-500">No immutable publication snapshot is available yet.</p>
                        )}
                    </div>
                </aside>
            </div>
        </article>
    );
}

export default function PublishingOperator({ workspace, campaigns, summary, permissions, bulk_result }: Props) {
    return (
        <>
            <Head title="Publishing operator" />
            <main className="min-h-screen bg-[radial-gradient(circle_at_top,#172033_0%,#0a0a0a_32%,#050505_100%)] text-neutral-100">
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    <header className="flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="text-xs font-semibold uppercase tracking-[0.22em] text-sky-300">VSN Marketing</p>
                                <span className="rounded-full border border-sky-400/20 bg-sky-400/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-sky-200">
                                    Governed operator
                                </span>
                            </div>
                            <h1 className="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl">Publishing operator</h1>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-neutral-400">
                                {workspace.name} · canonical campaign, approval, scheduling and publication evidence in one workspace-scoped surface.
                            </p>
                        </div>
                        <div className="rounded-2xl border border-white/10 bg-black/20 px-4 py-3 text-right">
                            <p className="text-xs text-neutral-500">Workspace authority</p>
                            <p className="mt-1 font-mono text-xs text-neutral-300">{shortHash(workspace.id)}</p>
                        </div>
                    </header>

                    <section className="mt-8 grid gap-3 sm:grid-cols-2 xl:grid-cols-5" aria-label="Workspace publishing summary">
                        <StatCard label="Campaigns" value={summary.campaigns} hint="Canonical records" />
                        <StatCard label="Approval queue" value={summary.needs_approval} hint="Needs review" />
                        <StatCard label="Scheduled" value={summary.scheduled} hint="Immutable timing" />
                        <StatCard label="Partial success" value={summary.partial_success} hint="Mixed target outcomes" />
                        <StatCard label="Provider attention" value={summary.provider_attention} hint="Actionable safe states" />
                    </section>

                    <ApprovalQueue
                        workspace={workspace}
                        campaigns={campaigns}
                        canApprove={permissions.can_approve}
                        bulkResult={bulk_result}
                    />

                    <section className="mt-8 space-y-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <h2 className="text-lg font-semibold text-white">Campaign operations</h2>
                                <p className="mt-1 text-sm text-neutral-500">Snapshot-bound previews and deterministic publication state.</p>
                            </div>
                            <p className="hidden text-xs text-neutral-600 sm:block">No provider credentials or raw provider evidence exposed</p>
                        </div>

                        {campaigns.length === 0 ? <EmptyState /> : campaigns.map((campaign) => <CampaignCard key={campaign.id} campaign={campaign} />)}
                    </section>
                </div>
            </main>
        </>
    );
}
