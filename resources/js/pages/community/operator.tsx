import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

type CommunityItem = {
    id: string;
    provider_key: string;
    type: string;
    body: string;
    moderation_state: string;
    assigned_actor_id: string | null;
    proposal_text: string | null;
    proposal_state: string | null;
    received_at: string;
    source_key: string;
    author_hash: string;
    provenance_hash: string;
};

type Props = {
    items: CommunityItem[];
    permissions: {
        can_moderate: boolean;
        can_propose: boolean;
        can_approve_ai: boolean;
    };
    notice: string | null;
    actions: { base: string };
};

export default function CommunityOperator({ items, permissions, notice, actions }: Props) {
    const [busy, setBusy] = useState<string | null>(null);
    const [assignees, setAssignees] = useState<Record<string, string>>({});
    const [proposals, setProposals] = useState<Record<string, string>>({});

    const post = (key: string, url: string, payload: Record<string, string>) => {
        if (busy !== null) return;
        setBusy(key);
        router.post(url, payload, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        });
    };

    return (
        <>
            <Head title="Community inbox" />
            <main className="mx-auto max-w-7xl space-y-6 p-6">
                <header>
                    <h1 className="text-2xl font-semibold">Community inbox</h1>
                    <p className="text-sm text-gray-600">
                        Tenant-scoped comments, mentions and messages. AI output remains a proposal until an operator approves it; this screen never sends to a provider.
                    </p>
                </header>

                {notice ? <div role="status" aria-live="polite">{notice}</div> : null}

                {items.length === 0 ? (
                    <p role="status">No verified community items are available.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table aria-label="Community inbox" className="min-w-full">
                            <thead>
                                <tr>
                                    <th scope="col">Source</th>
                                    <th scope="col">Message</th>
                                    <th scope="col">Moderation</th>
                                    <th scope="col">Assignment</th>
                                    <th scope="col">Response proposal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item) => {
                                    const key = item.id;
                                    const assignee = assignees[item.id] ?? item.assigned_actor_id ?? '';
                                    const proposal = proposals[item.id] ?? item.proposal_text ?? '';
                                    return (
                                        <tr key={item.id}>
                                            <td>
                                                <strong>{item.provider_key}</strong>
                                                <div>{item.type}</div>
                                                <div>{item.received_at}</div>
                                            </td>
                                            <td>{item.body}</td>
                                            <td>
                                                <div aria-label={'Moderation state for ' + item.id}>{item.moderation_state}</div>
                                                <button
                                                    type="button"
                                                    disabled={!permissions.can_moderate || busy !== null}
                                                    onClick={() => post(key, actions.base + '/' + item.id + '/moderate', { state: 'hidden' })}
                                                >
                                                    Hide
                                                </button>
                                                <button
                                                    type="button"
                                                    disabled={!permissions.can_moderate || busy !== null}
                                                    onClick={() => post(key, actions.base + '/' + item.id + '/moderate', { state: 'resolved' })}
                                                >
                                                    Resolve
                                                </button>
                                            </td>
                                            <td>
                                                <label>
                                                    Assignee actor
                                                    <input
                                                        aria-label={'Assignee actor for ' + item.id}
                                                        value={assignee}
                                                        onChange={(event) => setAssignees({ ...assignees, [item.id]: event.target.value })}
                                                        disabled={!permissions.can_moderate || busy !== null}
                                                    />
                                                </label>
                                                <button
                                                    type="button"
                                                    disabled={!permissions.can_moderate || busy !== null || assignee === ''}
                                                    onClick={() => post(key, actions.base + '/' + item.id + '/assign', { assignee_actor_id: assignee })}
                                                >
                                                    Assign
                                                </button>
                                            </td>
                                            <td>
                                                <label>
                                                    Draft response proposal
                                                    <textarea
                                                        aria-label={'Draft response proposal for ' + item.id}
                                                        value={proposal}
                                                        onChange={(event) => setProposals({ ...proposals, [item.id]: event.target.value })}
                                                        disabled={!permissions.can_propose || busy !== null}
                                                    />
                                                </label>
                                                <button
                                                    type="button"
                                                    disabled={!permissions.can_propose || busy !== null || proposal === ''}
                                                    onClick={() => post(key, actions.base + '/' + item.id + '/proposals', { text: proposal })}
                                                >
                                                    Save AI proposal
                                                </button>
                                                <div>Proposal state: {item.proposal_state ?? 'none'}</div>
                                                {item.proposal_state === 'proposed' ? (
                                                    <>
                                                        <button
                                                            type="button"
                                                            disabled={!permissions.can_moderate || !permissions.can_approve_ai || busy !== null}
                                                            onClick={() => post(key, actions.base + '/' + item.id + '/proposals/approve', {})}
                                                        >
                                                            Approve proposal
                                                        </button>
                                                        <button
                                                            type="button"
                                                            disabled={!permissions.can_moderate || busy !== null}
                                                            onClick={() => post(key, actions.base + '/' + item.id + '/proposals/reject', {})}
                                                        >
                                                            Reject proposal
                                                        </button>
                                                    </>
                                                ) : null}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </main>
        </>
    );
}
