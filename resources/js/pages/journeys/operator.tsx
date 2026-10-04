import { Head, router } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';

export type JourneyNodeType = 'trigger' | 'wait' | 'condition' | 'branch' | 'action' | 'goal' | 'exit' | 'end';
export type JourneyConfigValue = string | number | boolean | null | JourneyConfigValue[] | { [key: string]: JourneyConfigValue };
export type JourneyNode = { id: string; type: JourneyNodeType; config?: Record<string, JourneyConfigValue> };
export type JourneyEdge = { from: string; to: string; type?: 'default' | 'success' | 'failure' | 'true' | 'false' };
export type JourneyGraph = { schema_version: 1; nodes: JourneyNode[]; edges: JourneyEdge[] };
type EditorNode = JourneyNode & { editorKey: string };
type Journey = {
    id: string; name: string; status: string; draft_revision: number; lifecycle_revision: number;
    version: number | null; hash: string | null; graph: JourneyGraph | null;
};
type TimelineEntry = {
    id: string; journey_id: string; version: number; status: string; revision: number;
    history: Array<{ status?: string; node_id?: string; at?: string }> | null; created_at: string; updated_at: string;
};
type Props = {
    workspace_id: string; journeys: Journey[]; timeline: TimelineEntry[]; notice: Record<string, string> | null;
    actions: { create: string; base: string };
};

const NODE_TYPES: JourneyNodeType[] = ['trigger', 'wait', 'condition', 'branch', 'action', 'goal', 'exit', 'end'];
const OPERATORS = ['exists', 'not_exists', 'equals', 'not_equals', 'greater_than', 'less_than', 'contains'];
const EDGE_TYPES: NonNullable<JourneyEdge['type']>[] = ['default', 'true', 'false', 'success', 'failure'];
const TRANSITIONS_PER_PAGE = 10;
const STATUS_LABELS: Record<string, string> = {
    queued: 'Queued', waiting: 'Waiting', running: 'Running', retried: 'Retried', succeeded: 'Succeeded',
    blocked: 'Blocked', cancelled: 'Cancelled', exited: 'Exited', failed: 'Failed',
};
const DEFAULT_GRAPH: JourneyGraph = {
    schema_version: 1,
    nodes: [{ id: 'entry', type: 'trigger', config: { event: 'customer.created' } }, { id: 'finish', type: 'end' }],
    edges: [{ from: 'entry', to: 'finish', type: 'default' }],
};

export function simulateJourney(graph: JourneyGraph): string[] {
    const nodes = new Map(graph.nodes.map((node) => [node.id, node]));
    const start = graph.nodes.find((node) => node.type === 'trigger');
    if (!start) return ['No registered trigger node; there is no executable start.'];
    const trace: string[] = [`Start at trigger “${start.id}” (${String(start.config?.event ?? 'event not set')}).`];
    let current = start;
    const visited = new Set<string>([current.id]);
    for (let step = 0; step < Math.min(graph.nodes.length, 100); step += 1) {
        if (current.type === 'wait') trace.push(`Wait “${current.id}”: ${String(current.config?.seconds ?? '?')} seconds; simulation records the wait and never sleeps or schedules work.`);
        if (current.type === 'condition' || current.type === 'branch') {
            trace.push(`${current.type === 'branch' ? 'Branch' : 'Condition'} “${current.id}” is not evaluated without a real subject/event; both outcomes remain visible.`);
        }
        if (current.type === 'action') trace.push(`Action “${current.id}” is blocked in simulation. No provider dispatch or external side effect occurs.`);
        if (current.type === 'goal') trace.push(`Goal “${current.id}” ends this simulated path if its event matches.`);
        if (current.type === 'exit') trace.push(`Exit “${current.id}” ends this simulated path if its event matches.`);
        if (current.type === 'end') {
            trace.push(`Journey ends at “${current.id}”.`);
            return trace;
        }
        const outgoing = graph.edges.filter((edge) => edge.from === current.id);
        if (outgoing.length === 0) {
            trace.push(`No outgoing transition from “${current.id}”; the path is incomplete.`);
            return trace;
        }
        if (outgoing.length > 1) {
            trace.push(`Possible transitions: ${outgoing.map((edge) => `${edge.type ?? 'default'} → ${edge.to}`).join('; ')}.`);
            return trace;
        }
        const next = nodes.get(outgoing[0].to);
        if (!next || visited.has(next.id)) {
            trace.push('The graph contains a missing or repeated node; server validation is required before publish.');
            return trace;
        }
        trace.push(`Transition ${current.id} → ${next.id} (${outgoing[0].type ?? 'default'}).`);
        current = next;
        visited.add(current.id);
    }
    trace.push('Simulation stopped at the safe traversal bound.');
    return trace;
}

export function validateJourneyGraph(graph: JourneyGraph): string[] {
    const issues: string[] = [];
    if (graph.schema_version !== 1) issues.push('Use graph schema version 1.');
    if (graph.nodes.length === 0) issues.push('Add at least one node.');
    if (graph.nodes.length > 100) issues.push('The graph exceeds the 100-node runtime limit.');
    const ids = new Set(graph.nodes.map((node) => node.id));
    if (ids.size !== graph.nodes.length || graph.nodes.some((node) => !node.id.trim())) issues.push('Node IDs must be unique and non-empty.');
    if (graph.nodes.filter((node) => node.type === 'trigger').length !== 1) issues.push('Exactly one registered trigger node is required.');
    if (!graph.nodes.some((node) => ['end', 'goal', 'exit'].includes(node.type))) issues.push('Add an end, goal, or exit node.');
    if (graph.edges.some((edge) => !ids.has(edge.from) || !ids.has(edge.to))) issues.push('Every transition must reference an existing node.');
    const edgeKeys = new Set<string>();
    const outgoingByNode = new Map<string, JourneyEdge[]>();
    const adjacency = new Map<string, string[]>();
    for (const node of graph.nodes) {
        outgoingByNode.set(node.id, []);
        adjacency.set(node.id, []);
    }
    for (const edge of graph.edges) {
        const key = `${edge.from}\u0000${edge.to}\u0000${edge.type ?? ''}`;
        edgeKeys.add(key);
        outgoingByNode.get(edge.from)?.push(edge);
        adjacency.get(edge.from)?.push(edge.to);
    }
    if (edgeKeys.size !== graph.edges.length) issues.push('Duplicate transitions are not allowed.');
    for (const node of graph.nodes) {
        const outgoing = outgoingByNode.get(node.id) ?? [];
        if (['end', 'goal', 'exit'].includes(node.type) && outgoing.length > 0) issues.push(`Terminal node “${node.id}” cannot have outgoing transitions.`);
        if (['branch', 'condition'].includes(node.type)) {
            const types = outgoing.map((edge) => edge.type ?? 'default').sort();
            if (types.join(',') !== 'false,true') issues.push(`Condition “${node.id}” needs exactly one true and one false transition.`);
        } else if (outgoing.some((edge) => (edge.type ?? 'default') !== 'default')) {
            issues.push(`Node “${node.id}” only supports default transitions.`);
        }
        if (node.type === 'trigger' && !/^[a-z][a-z0-9_.-]{1,190}$/.test(String(node.config?.event ?? ''))) issues.push(`Trigger “${node.id}” needs a canonical event name.`);
        if (node.type === 'wait' && (!Number.isInteger(node.config?.seconds) || Number(node.config?.seconds) < 1 || Number(node.config?.seconds) > 31536000)) issues.push(`Wait “${node.id}” needs a duration from 1 to 31,536,000 seconds.`);
        if (['condition', 'branch'].includes(node.type) && (!node.config?.field || !OPERATORS.includes(String(node.config?.operator)))) issues.push(`Condition “${node.id}” needs a field and registered operator.`);
        if (node.type === 'action' && (!node.config?.capability || !node.config?.input || Array.isArray(node.config.input))) issues.push(`Action “${node.id}” needs a capability and structured input.`);
    }
    const visiting = new Set<string>(); const visited = new Set<string>();
    const visit = (id: string): boolean => {
        if (visiting.has(id)) return false;
        if (visited.has(id)) return true;
        visiting.add(id);
        for (const next of adjacency.get(id) ?? []) if (!visit(next)) return false;
        visiting.delete(id); visited.add(id); return true;
    };
    if ([...ids].some((id) => !visit(id))) issues.push('Cycles are not allowed in a journey graph.');
    return [...new Set(issues)];
}

function statusLabel(status: string): string {
    return STATUS_LABELS[status] ?? status.replaceAll('_', ' ');
}

function nodeWithType(type: JourneyNodeType, index: number): JourneyNode {
    const id = `${type}-${index}`;
    if (type === 'trigger') return { id, type, config: { event: 'customer.created' } };
    if (type === 'wait') return { id, type, config: { seconds: 60 } };
    if (type === 'condition' || type === 'branch') return { id, type, config: { field: 'contact.status', operator: 'exists' } };
    if (type === 'action') return { id, type, config: { capability: 'email.send', input: { template: 'review-required' } } };
    if (type === 'goal' || type === 'exit') return { id, type, config: { event: 'customer.converted' } };
    return { id, type };
}

function updateNode(graph: JourneyGraph, index: number, next: JourneyNode): JourneyGraph {
    return { ...graph, nodes: graph.nodes.map((node, nodeIndex) => nodeIndex === index ? next : node) };
}

function editorNodesFor(journey: Journey | null, graph: JourneyGraph): EditorNode[] {
    const prefix = journey?.id ?? 'new';
    return graph.nodes.map((node, index) => ({ ...node, editorKey: `${prefix}-${index}` }));
}

function NodeEditor({ node, index, disabled, onChange, onRemove, inputRef }: {
    node: JourneyNode; index: number; disabled: boolean; onChange: (node: JourneyNode) => void; onRemove: () => void;
    inputRef: (element: HTMLInputElement | null) => void;
}) {
    const config = node.config ?? {};
    const setConfig = (key: string, value: JourneyConfigValue) => onChange({ ...node, config: { ...config, [key]: value } });
    return <fieldset className="min-w-0 rounded-xl border border-white/15 bg-black/20 p-4">
        <legend className="px-2 text-sm font-semibold">Node {index + 1}: {node.type}</legend>
        <div className="grid min-w-0 gap-3 sm:grid-cols-2">
            <label className="text-sm">Node ID
                <input ref={inputRef} aria-label={`Node ${index + 1} ID`} disabled={disabled} value={node.id} onChange={(event) => onChange({ ...node, id: event.target.value })} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2" />
            </label>
            <label className="text-sm">Type
                <select aria-label={`Node ${index + 1} type`} disabled={disabled} value={node.type} onChange={(event) => onChange({ ...nodeWithType(event.target.value as JourneyNodeType, index + 1), id: node.id })} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2">
                    {NODE_TYPES.map((type) => <option key={type} value={type}>{type}</option>)}
                </select>
            </label>
            {['trigger', 'goal', 'exit'].includes(node.type) && <label className="text-sm">Canonical event
                <input disabled={disabled} value={String(config.event ?? '')} onChange={(event) => setConfig('event', event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2" />
            </label>}
            {node.type === 'wait' && <label className="text-sm">Wait seconds
                <input type="number" min="1" max="31536000" disabled={disabled} value={Number(config.seconds ?? 60)} onChange={(event) => setConfig('seconds', Number(event.target.value))} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2" />
            </label>}
            {['condition', 'branch'].includes(node.type) && <>
                <label className="text-sm">Registered field
                    <input disabled={disabled} value={String(config.field ?? '')} onChange={(event) => setConfig('field', event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2" />
                </label>
                <label className="text-sm">Operator
                    <select disabled={disabled} value={String(config.operator ?? 'exists')} onChange={(event) => setConfig('operator', event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2">
                        {OPERATORS.map((operator) => <option key={operator} value={operator}>{operator.replaceAll('_', ' ')}</option>)}
                    </select>
                </label>
                {!['exists', 'not_exists'].includes(String(config.operator)) && <label className="text-sm">Comparison value
                    <input disabled={disabled} value={String(config.value ?? '')} onChange={(event) => setConfig('value', event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2" />
                </label>}
            </>}
            {node.type === 'action' && <label className="text-sm">Registered capability (simulation remains blocked)
                <input disabled={disabled} value={String(config.capability ?? '')} onChange={(event) => setConfig('capability', event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2" />
            </label>}
        </div>
        <button type="button" aria-label={`Remove node ${node.id}`} disabled={disabled || node.type === 'trigger'} onClick={onRemove} className="mt-3 rounded-lg border border-rose-300/40 px-3 py-2 text-sm text-rose-100 disabled:opacity-40">Remove node</button>
    </fieldset>;
}

export default function JourneyOperator({ workspace_id, journeys, timeline, notice, actions }: Props) {
    const [selectedId, setSelectedId] = useState(journeys[0]?.id ?? '');
    const selected = journeys.find((journey) => journey.id === selectedId) ?? journeys[0] ?? null;
    const [name, setName] = useState(selected?.name ?? '');
    const [graph, setGraph] = useState<JourneyGraph>(selected?.graph ?? DEFAULT_GRAPH);
    const [newName, setNewName] = useState('');
    const [busy, setBusy] = useState(false);
    const [clientMessage, setClientMessage] = useState('');
    const [pendingConfirmation, setPendingConfirmation] = useState<string | null>(null);
    const [showAllNodes, setShowAllNodes] = useState(false);
    const [visibleTransitionCount, setVisibleTransitionCount] = useState(TRANSITIONS_PER_PAGE);
    const nodeEditorSequence = useRef(0);
    const [editorNodes, setEditorNodes] = useState<EditorNode[]>(() => editorNodesFor(selected, selected?.graph ?? DEFAULT_GRAPH));
    const confirmationReturnFocus = useRef<HTMLElement | null>(null);
    const confirmationCancel = useRef<HTMLButtonElement | null>(null);
    const confirmationSubmit = useRef<HTMLButtonElement | null>(null);
    const [focusNodeId, setFocusNodeId] = useState<string | null>(null);
    const selectedTimeline = timeline.filter((entry) => entry.journey_id === selected?.id);
    const simulation = useMemo(() => simulateJourney(graph), [graph]);
    useEffect(() => {
        if (notice?.status === 'created' && notice.journey_id) setSelectedId(notice.journey_id);
    }, [notice]);
    useEffect(() => {
        if (selected) { const nextGraph = selected.graph ?? DEFAULT_GRAPH; setName(selected.name); setGraph(nextGraph); setEditorNodes(editorNodesFor(selected, nextGraph)); }
    }, [selected?.id]);
    useLayoutEffect(() => {
        if (focusNodeId === null) return;
        const target = document.querySelector<HTMLInputElement>(`[data-editor-key="${CSS.escape(focusNodeId)}"]`);
        target?.focus();
        setFocusNodeId(null);
    }, [focusNodeId, graph.nodes, editorNodes]);
    useEffect(() => {
        if (!pendingConfirmation) {
            confirmationReturnFocus.current?.focus();
            confirmationReturnFocus.current = null;
            return;
        }

        confirmationCancel.current?.focus();
        const handleDialogKeys = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                setPendingConfirmation(null);
                return;
            }
            if (event.key !== 'Tab') return;

            const first = confirmationCancel.current;
            const last = confirmationSubmit.current;
            if (!first || !last) return;
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', handleDialogKeys);
        return () => document.removeEventListener('keydown', handleDialogKeys);
    }, [pendingConfirmation]);
    const tooLarge = graph.nodes.length > 100;
    const validation = useMemo(() => {
        return validateJourneyGraph(graph);
    }, [graph]);
    const visibleNodeCount = showAllNodes ? graph.nodes.length : Math.min(graph.nodes.length, 25);

    const choose = (id: string) => {
        const journey = journeys.find((item) => item.id === id);
        if (!journey) return;
        setSelectedId(id); setName(journey.name); setGraph(journey.graph ?? DEFAULT_GRAPH); setClientMessage('');
        setShowAllNodes(false); setVisibleTransitionCount(TRANSITIONS_PER_PAGE);
    };
    const submit = (url: string, data: Parameters<typeof router.post>[1]) => {
        if (busy) return;
        setBusy(true); setClientMessage('');
        router.post(url, data, {
            preserveScroll: true,
            onError: () => setClientMessage('The request could not be completed. Check permissions and connection, then refresh to verify the saved version before retrying.'),
            onHttpException: (response) => {
                if (response.status === 401 || response.status === 403) {
                    setClientMessage(response.status === 401
                        ? 'Your session expired. Sign in again, then reload this workspace.'
                        : 'Permission denied for this workspace action. Ask a workspace administrator to check your role.');
                } else if (response.status === 408 || response.status === 504) {
                    setClientMessage('The request timed out. Reload the journey to verify whether the change was saved before retrying.');
                } else {
                    setClientMessage(`The server returned HTTP ${response.status}. Reload the journey and verify its current state before retrying.`);
                }
                return false;
            },
            onNetworkError: () => {
                setClientMessage('The request timed out or the server could not be reached. Reload the journey to verify whether the change was saved before retrying.');
                return false;
            },
            onFinish: () => setBusy(false),
        });
    };
    const createJourney = () => {
        if (!newName.trim()) { setClientMessage('Enter a journey name before creating a draft.'); return; }
        submit(actions.create, { name: newName.trim() });
    };
    const saveDraft = () => {
        if (!selected || validation.length > 0 || selected.status !== 'draft') return;
        submit(`${actions.base}/${selected.id}/draft`, { name: name.trim(), graph, expected_revision: selected.draft_revision });
    };
    const startConfirmation = (action: string) => {
        confirmationReturnFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        setPendingConfirmation(action);
    };
    const confirmAction = () => {
        if (!selected || !pendingConfirmation) return;
        const action = pendingConfirmation; setPendingConfirmation(null);
        if (action === 'publish') {
            submit(`${actions.base}/${selected.id}/publish`, { expected_revision: selected.draft_revision, confirmed: true });
            return;
        }
        submit(`${actions.base}/${selected.id}/lifecycle/${action}`, { expected_revision: selected.lifecycle_revision, confirmed: true });
    };
    const addNode = (type: JourneyNodeType) => {
        if (graph.nodes.length >= 100) return;
        const usedIds = new Set(graph.nodes.map((node) => node.id));
        let index = graph.nodes.length + 1;
        while (usedIds.has(`${type}-${index}`)) index += 1;
        const node = nodeWithType(type, index);
        const editorKey = `${selected?.id ?? 'new'}-new-${++nodeEditorSequence.current}`;
        const nextEditorNodes = [...editorNodes];
        const editorInsertAt = nextEditorNodes.findIndex((candidate) => ['end', 'goal', 'exit'].includes(candidate.type));
        nextEditorNodes.splice(editorInsertAt < 0 ? nextEditorNodes.length : editorInsertAt, 0, { ...node, editorKey });
        const nextNodes = [...graph.nodes];
        const graphInsertAt = nextNodes.findIndex((candidate) => ['end', 'goal', 'exit'].includes(candidate.type));
        nextNodes.splice(graphInsertAt < 0 ? nextNodes.length : graphInsertAt, 0, node);
        setShowAllNodes(true);
        setFocusNodeId(editorKey);
        setEditorNodes(nextEditorNodes);
        setGraph((current) => ({ ...current, nodes: nextNodes }));
    };
    const addEdge = () => {
        if (graph.nodes.length < 2) return;
        setGraph((current) => ({ ...current, edges: [...current.edges, { from: current.nodes[current.nodes.length - 2].id, to: current.nodes[current.nodes.length - 1].id, type: 'default' }] }));
    };
    const lifecycleActions: Record<string, string[]> = {
        draft: ['publish', 'archive'], published: ['activate', 'cancel', 'archive'],
        active: ['pause', 'cancel'], paused: ['resume', 'cancel', 'archive'], cancelled: ['archive'], archived: [],
    };
    const confirmationText: Record<string, string> = {
        publish: `Publish immutable version ${selected?.version ? selected.version + 1 : 1} of ${selected?.name}?`,
        activate: `Activate ${selected?.name} at lifecycle revision ${selected?.lifecycle_revision}?`,
        pause: `Pause new journey progression for ${selected?.name}? Existing work will remain durable.`,
        resume: `Resume ${selected?.name} from its current durable state?`,
        cancel: `Cancel ${selected?.name} and its active executions? This cannot be undone.`,
        archive: `Archive ${selected?.name}? Archived journeys cannot be activated.`,
    };

    return <>
        <Head title="Journeys" />
        <main className="min-h-screen bg-[radial-gradient(circle_at_top,#172033_0%,#0a0a0a_32%,#050505_100%)] text-neutral-100">
            <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                <header>
                    <p className="text-xs font-semibold uppercase tracking-[0.22em] text-sky-300">VSN Marketing · Journeys</p>
                    <h1 className="mt-3 text-3xl font-semibold tracking-tight text-white">Journey builder and timeline</h1>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-neutral-400">Author versioned graphs, inspect a side-effect-free simulation, and review bounded execution history. Simulated actions never contact a provider.</p>
                </header>

                {(notice || clientMessage || busy) && <div className="mt-5 rounded-xl border border-sky-300/30 bg-sky-300/10 p-4 text-sm" role="status" aria-live="polite">
                    {busy ? 'Saving journey state…' : clientMessage || (notice?.status === 'stale' ? 'This journey changed elsewhere. Refresh before editing or retrying.' : notice?.code ? `Request not applied: ${notice.code.replaceAll('_', ' ')}${notice.path ? ` (${notice.path})` : ''}.` : notice?.status ? `Journey state: ${notice.status.replaceAll('_', ' ')}.` : '')}
                </div>}

                {journeys.length === 0 ? <section className="mt-7 rounded-3xl border border-white/10 bg-white/[0.035] p-6" aria-labelledby="empty-heading">
                    <h2 id="empty-heading" className="text-xl font-semibold">No journeys in this workspace</h2>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-neutral-400">Create a named draft to start. A starter graph is provided, but it is not published or activated until you review and confirm each step.</p>
                    <div className="mt-4 flex flex-col gap-3 sm:flex-row">
                        <label className="flex-1 text-sm">Journey name
                            <input value={newName} maxLength={191} onChange={(event) => setNewName(event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-3" />
                        </label>
                        <button type="button" disabled={busy || !newName.trim()} onClick={createJourney} className="self-end rounded-lg bg-white px-4 py-3 text-sm font-semibold text-black disabled:opacity-40">Create draft</button>
                    </div>
                </section> : <div className="mt-7 grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                    <section className="min-w-0 space-y-5">
                        <div className="rounded-2xl border border-white/10 bg-white/[0.035] p-4">
                            <label htmlFor="journey-select" className="text-sm font-medium">Select journey</label>
                            <select id="journey-select" value={selected?.id ?? ''} onChange={(event) => choose(event.target.value)} className="mt-2 block w-full rounded-lg border border-white/15 bg-neutral-900 p-3">
                                {journeys.map((journey) => <option key={journey.id} value={journey.id}>{journey.name} · {statusLabel(journey.status)}</option>)}
                            </select>
                            {selected && <p className="mt-2 text-xs text-neutral-400">State {statusLabel(selected.status)} · draft revision {selected.draft_revision} · lifecycle revision {selected.lifecycle_revision} · published version {selected.version ?? 'none'}</p>}
                        </div>
                        {selected && <section className="min-w-0 rounded-3xl border border-white/10 bg-white/[0.035] p-5 sm:p-6" aria-labelledby="builder-heading">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                <div><h2 id="builder-heading" className="text-xl font-semibold">Edit draft</h2><p className="mt-1 text-sm text-neutral-400">Only draft journeys are editable. The server validates and canonicalizes before a version is published.</p></div>
                                {selected.hash && <span className="font-mono text-xs text-neutral-500">v{selected.version} · {selected.hash.slice(0, 12)}</span>}
                            </div>
                            {selected.status === 'draft' ? <>
                                <label className="mt-4 block text-sm">Journey name<input value={name} maxLength={191} onChange={(event) => setName(event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-3" /></label>
                                {tooLarge ? <p role="alert" className="mt-4 rounded-lg border border-rose-300/40 p-3 text-sm">This graph exceeds the 100-node limit. Editing is paused; remove nodes before continuing.</p> : <div className="mt-4 space-y-3">
                                    {graph.nodes.slice(0, visibleNodeCount).map((node, index) => {
                                        const editorNode = editorNodes[index] ?? { ...node, editorKey: `${selected.id}-${index}` };
                                        return <NodeEditor key={editorNode.editorKey} node={node} index={index} disabled={busy} inputRef={(element) => { if (element) element.dataset.editorKey = editorNode.editorKey; }} onChange={(next) => setGraph((current) => updateNode(current, index, next))} onRemove={() => { const adjacentIndex = index + 1 < graph.nodes.length ? index + 1 : index - 1; setFocusNodeId(editorNodes[adjacentIndex]?.editorKey ?? null); setEditorNodes((current) => current.filter((_, itemIndex) => itemIndex !== index)); setGraph((current) => ({ ...current, nodes: current.nodes.filter((_, itemIndex) => itemIndex !== index), edges: current.edges.filter((edge) => edge.from !== node.id && edge.to !== node.id) })); }} />;
                                    })}
                                    {graph.nodes.length > 25 && <div className="flex flex-wrap items-center gap-3 rounded-lg border border-white/10 p-3 text-sm">
                                        <p role="status" aria-live="polite">{showAllNodes ? `Showing all ${graph.nodes.length} nodes.` : `Showing the first 25 of ${graph.nodes.length} nodes.`}</p>
                                        <button type="button" aria-expanded={showAllNodes} onClick={() => setShowAllNodes((value) => !value)} className="rounded-lg border border-white/20 px-3 py-2">{showAllNodes ? 'Show fewer nodes' : `Show all ${graph.nodes.length} nodes`}</button>
                                    </div>}
                                </div>}
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <label className="text-sm">Add node<select value="" disabled={busy || graph.nodes.length >= 100} onChange={(event) => { if (event.target.value) addNode(event.target.value as JourneyNodeType); }} className="ml-2 rounded-lg border border-white/15 bg-neutral-900 p-2"><option value="">Choose…</option>{NODE_TYPES.filter((type) => type !== 'trigger').map((type) => <option key={type} value={type}>{type}</option>)}</select></label>
                                    <button type="button" disabled={busy || graph.nodes.length > 100} onClick={addEdge} className="rounded-lg border border-white/20 px-3 py-2 text-sm">Add transition</button>
                                </div>
                                {graph.edges.length > 0 && <div className="mt-4 space-y-2" aria-label="Journey transitions">
                                    <h3 className="text-sm font-semibold">Transitions</h3>
                                    {graph.edges.slice(0, visibleTransitionCount).map((edge, index) => <div key={`${index}-${edge.from}-${edge.to}`} className="grid min-w-0 gap-2 sm:grid-cols-[1fr_1fr_1fr_auto]">
                                        <label className="sr-only" htmlFor={`edge-from-${index}`}>Transition {index + 1} from</label><select id={`edge-from-${index}`} value={edge.from} onChange={(event) => setGraph((current) => ({ ...current, edges: current.edges.map((item, i) => i === index ? { ...item, from: event.target.value } : item) }))} className="min-w-0 rounded-lg border border-white/15 bg-neutral-900 p-2">{graph.nodes.map((node) => <option key={node.id} value={node.id}>{node.id}</option>)}</select>
                                        <label className="sr-only" htmlFor={`edge-type-${index}`}>Transition {index + 1} outcome</label><select id={`edge-type-${index}`} value={edge.type ?? 'default'} onChange={(event) => setGraph((current) => ({ ...current, edges: current.edges.map((item, i) => i === index ? { ...item, type: event.target.value as JourneyEdge['type'] } : item) }))} className="min-w-0 rounded-lg border border-white/15 bg-neutral-900 p-2">{EDGE_TYPES.map((type) => <option key={type} value={type}>{type}</option>)}</select>
                                        <label className="sr-only" htmlFor={`edge-to-${index}`}>Transition {index + 1} to</label><select id={`edge-to-${index}`} value={edge.to} onChange={(event) => setGraph((current) => ({ ...current, edges: current.edges.map((item, i) => i === index ? { ...item, to: event.target.value } : item) }))} className="min-w-0 rounded-lg border border-white/15 bg-neutral-900 p-2">{graph.nodes.map((node) => <option key={node.id} value={node.id}>{node.id}</option>)}</select>
                                        <button type="button" aria-label={`Remove transition ${index + 1}`} onClick={() => setGraph((current) => ({ ...current, edges: current.edges.filter((_, i) => i !== index) }))} className="rounded-lg border border-rose-300/30 px-3 py-2 text-sm text-rose-100">Remove</button>
                                    </div>)}
                                    {graph.edges.length > TRANSITIONS_PER_PAGE && <div className="flex flex-wrap items-center gap-3 rounded-lg border border-white/10 p-3 text-sm">
                                        <p role="status" aria-live="polite">Showing {Math.min(visibleTransitionCount, graph.edges.length)} of {graph.edges.length} transitions.</p>
                                        {visibleTransitionCount < graph.edges.length && <button type="button" onClick={() => setVisibleTransitionCount((count) => Math.min(count + TRANSITIONS_PER_PAGE, graph.edges.length))} className="rounded-lg border border-white/20 px-3 py-2">Show 10 more transitions</button>}
                                        {visibleTransitionCount > TRANSITIONS_PER_PAGE && <button type="button" onClick={() => setVisibleTransitionCount(TRANSITIONS_PER_PAGE)} className="rounded-lg border border-white/20 px-3 py-2">Show fewer transitions</button>}
                                    </div>}
                                </div>}
                                {validation.length > 0 && <div className="mt-4 rounded-xl border border-amber-300/30 p-4" aria-labelledby="validation-heading"><h3 id="validation-heading" className="font-semibold">Validation summary</h3><ul className="mt-2 list-disc space-y-1 pl-5 text-sm">{validation.map((issue) => <li key={issue}>{issue}</li>)}</ul></div>}
                                <div className="mt-5 flex flex-wrap gap-2">
                                    <button type="button" disabled={busy || validation.length > 0} onClick={saveDraft} className="rounded-lg border border-white/20 px-4 py-2.5 text-sm font-semibold disabled:opacity-40">Save draft (r{selected.draft_revision})</button>
                                    <button type="button" disabled={busy || validation.length > 0} onClick={() => startConfirmation('publish')} className="rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-black disabled:opacity-40">Review and publish</button>
                                </div>
                            </> : <p className="mt-4 rounded-xl border border-white/10 p-4 text-sm text-neutral-300">This version is immutable. Create another draft to make changes; current history remains pinned.</p>}
                        </section>}
                        {selected && <section className="rounded-3xl border border-sky-300/15 bg-sky-300/[0.04] p-5" aria-labelledby="simulation-heading">
                            <div className="flex flex-wrap items-center justify-between gap-2"><h2 id="simulation-heading" className="text-xl font-semibold">Draft simulation</h2><span className="rounded-full border border-sky-200/20 px-3 py-1 text-xs">Side effects: none</span></div>
                            <p className="mt-1 text-sm text-neutral-400">Uses graph structure only. Conditions and provider results are not fabricated; unresolved paths are shown explicitly.</p>
                            <ol className="mt-4 list-decimal space-y-2 pl-5 text-sm leading-6" aria-live="polite">{simulation.map((step, index) => <li key={`${index}-${step}`}>{step}</li>)}</ol>
                        </section>}
                    </section>

                    <aside className="min-w-0 space-y-5">
                        {selected && <section className="rounded-3xl border border-white/10 bg-white/[0.035] p-5" aria-labelledby="lifecycle-heading">
                            <h2 id="lifecycle-heading" className="text-lg font-semibold">Lifecycle</h2>
                            <p className="mt-1 text-sm text-neutral-400">Each transition requires confirmation and the current lifecycle revision.</p>
                            <div className="mt-4 flex flex-wrap gap-2">{(lifecycleActions[selected.status] ?? []).map((action) => <button key={action} type="button" disabled={busy} onClick={() => startConfirmation(action)} className="rounded-lg border border-white/20 px-3 py-2 text-sm capitalize">{action}</button>)}</div>
                            {selected.status === 'draft' && journeys.length < 100 && <div className="mt-5 border-t border-white/10 pt-4"><label className="block text-sm">New draft name<input value={newName} maxLength={191} onChange={(event) => setNewName(event.target.value)} className="mt-1 block w-full rounded-lg border border-white/15 bg-neutral-900 p-2" /></label><button type="button" disabled={busy || !newName.trim()} onClick={createJourney} className="mt-2 rounded-lg border border-white/20 px-3 py-2 text-sm disabled:opacity-40">Create another draft</button></div>}
                        </section>}
                        <section className="rounded-3xl border border-white/10 bg-white/[0.035] p-5" aria-labelledby="timeline-heading">
                            <div className="flex items-center justify-between gap-2"><h2 id="timeline-heading" className="text-lg font-semibold">Execution timeline</h2><span className="text-xs text-neutral-400">Latest 100</span></div>
                            {selectedTimeline.length === 0 ? <p className="mt-3 rounded-xl border border-dashed border-white/15 p-4 text-sm text-neutral-400">No executions yet. Draft simulation does not create execution records.</p> : <ol className="mt-4 max-h-[36rem] space-y-3 overflow-y-auto" aria-label="Recent journey executions">
                                {selectedTimeline.map((entry) => <li key={entry.id} className="rounded-xl border border-white/10 p-3">
                                    <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-mono text-xs text-neutral-400">{entry.id.slice(0, 8)} · v{entry.version}</span><span className="rounded-full border border-white/20 px-2 py-1 text-xs capitalize">{statusLabel(entry.status)}</span></div>
                                    <p className="mt-2 text-xs text-neutral-400">Revision {entry.revision} · updated {entry.updated_at}</p>
                                    {entry.history && entry.history.length > 0 && <ul className="mt-2 border-l border-white/20 pl-3 text-xs text-neutral-300">{entry.history.slice(-8).map((event, index) => <li key={`${index}-${event.node_id ?? event.status ?? 'event'}`} className="py-1">{event.status ? statusLabel(event.status) : 'Transition'}{event.node_id ? ` · ${event.node_id}` : ''}{event.at ? ` · ${event.at}` : ''}</li>)}</ul>}
                                </li>)}
                            </ol>}
                            {selectedTimeline.length === 100 && <p className="mt-3 text-xs text-neutral-400" role="note">Showing the newest 100 records. Narrow by journey or time range in a later release.</p>}
                        </section>
                    </aside>
                </div>}
                {journeys.length > 0 && journeys.length >= 100 && <p className="mt-4 rounded-lg border border-amber-300/30 p-3 text-sm" role="status">Large workspace state: showing a bounded list of 100 journeys.</p>}
                {selected && selected.status === 'stale' && <p className="mt-4 rounded-lg border border-amber-300/30 p-3 text-sm" role="alert">This view is stale. Reload to get the latest draft and lifecycle revisions.</p>}
                {pendingConfirmation && <div className="fixed inset-0 z-50 grid place-items-center bg-black/80 p-4" role="presentation"><section role="dialog" aria-modal="true" aria-labelledby="confirm-heading" aria-describedby="confirm-description" className="w-full max-w-lg rounded-2xl border border-white/15 bg-neutral-950 p-6 shadow-2xl">
                    <h2 id="confirm-heading" className="text-xl font-semibold">Confirm {pendingConfirmation}</h2><p id="confirm-description" className="mt-3 text-sm leading-6 text-neutral-300">{confirmationText[pendingConfirmation]}</p>
                    {pendingConfirmation === 'publish' && <p className="mt-2 text-sm text-amber-100">Publishing creates an immutable version. It does not activate the journey or send provider messages.</p>}
                    {pendingConfirmation === 'cancel' && <p className="mt-2 text-sm text-amber-100">Queued work will be marked cancelled in this workspace. An action already claimed by a worker may still be sent or finish; a provider request cannot be recalled.</p>}
                    <div className="mt-5 flex flex-wrap justify-end gap-2"><button ref={confirmationCancel} type="button" onClick={() => setPendingConfirmation(null)} className="rounded-lg border border-white/20 px-4 py-2.5">Go back</button><button ref={confirmationSubmit} type="button" onClick={confirmAction} className="rounded-lg bg-white px-4 py-2.5 font-semibold text-black">Confirm {pendingConfirmation}</button></div>
                </section></div>}
            </div>
        </main>
    </>;
}
