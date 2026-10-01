// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import JourneyOperator, { simulateJourney, validateJourneyGraph, type JourneyGraph } from './operator';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post },
}));

afterEach(() => {
    cleanup();
    post.mockReset();
});

const graph: JourneyGraph = {
    schema_version: 1,
    nodes: [
        { id: 'entry', type: 'trigger', config: { event: 'customer.created' } },
        { id: 'wait-a', type: 'wait', config: { seconds: 60 } },
        { id: 'send', type: 'action', config: { capability: 'email.send', input: { template: 'welcome' } } },
        { id: 'finish', type: 'end' },
    ],
    edges: [
        { from: 'entry', to: 'wait-a' },
        { from: 'wait-a', to: 'send' },
        { from: 'send', to: 'finish' },
    ],
};

const journey = {
    id: 'journey-1', name: 'Welcome', status: 'draft', draft_revision: 3,
    lifecycle_revision: 0, version: null, hash: null, graph,
};

function renderOperator(overrides: Partial<React.ComponentProps<typeof JourneyOperator>> = {}) {
    return render(<JourneyOperator
        workspace_id="workspace-123456789"
        journeys={[journey]}
        timeline={[]}
        notice={null}
        actions={{ create: '/workspaces/workspace-123456789/journeys', base: '/workspaces/workspace-123456789/journeys' }}
        {...overrides}
    />);
}

test('simulator describes waits and blocks actions without producing side effects', () => {
    const trace = simulateJourney(graph);

    expect(trace.join('\n')).toContain('60 seconds');
    expect(trace.join('\n')).toContain('Action “send” is blocked in simulation');
    expect(trace.at(-1)).toContain('Journey ends');
});

test('simulator reports both condition outcomes without inventing a branch decision', () => {
    const branchGraph: JourneyGraph = {
        schema_version: 1,
        nodes: [
            { id: 'entry', type: 'trigger', config: { event: 'customer.created' } },
            { id: 'condition', type: 'condition', config: { field: 'contact.status', operator: 'exists' } },
            { id: 'yes', type: 'goal', config: { event: 'customer.converted' } },
            { id: 'no', type: 'exit', config: { event: 'customer.unsubscribed' } },
        ],
        edges: [
            { from: 'entry', to: 'condition' },
            { from: 'condition', to: 'yes', type: 'true' },
            { from: 'condition', to: 'no', type: 'false' },
        ],
    };

    const trace = simulateJourney(branchGraph).join('\n');
    expect(trace).toContain('both outcomes remain visible');
    expect(trace).toContain('true → yes');
    expect(trace).toContain('false → no');
});

test('graph validation enforces a bounded node count and condition transition shape', () => {
    const oversized = { ...graph, nodes: Array.from({ length: 101 }, (_, index) => ({ id: `n-${index}`, type: 'end' as const })), edges: [] };
    const invalidBranch: JourneyGraph = {
        schema_version: 1,
        nodes: [
            { id: 'entry', type: 'trigger', config: { event: 'customer.created' } },
            { id: 'branch', type: 'branch', config: { field: 'contact.status', operator: 'exists' } },
            { id: 'finish', type: 'end' },
        ],
        edges: [{ from: 'entry', to: 'branch' }, { from: 'branch', to: 'finish', type: 'true' }],
    };

    expect(validateJourneyGraph(oversized)).toContain('The graph exceeds the 100-node runtime limit.');
    expect(validateJourneyGraph(invalidBranch)).toContain('Condition “branch” needs exactly one true and one false transition.');
});

test('adding and removing a node moves keyboard focus to the new and adjacent editor controls', () => {
    renderOperator();

    fireEvent.change(screen.getByLabelText('Add node'), { target: { value: 'action' } });
    expect(screen.getByLabelText('Node 4 ID')).toHaveFocus();

    fireEvent.click(screen.getByRole('button', { name: 'Remove node action-5' }));
    expect(screen.getByLabelText('Node 4 ID')).toHaveFocus();
});

test('editing a node identifier keeps the field mounted and focused while typing', () => {
    renderOperator();
    const input = screen.getByLabelText('Node 2 ID');
    input.focus();

    fireEvent.change(input, { target: { value: 'delay' } });

    expect(screen.getByLabelText('Node 2 ID')).toHaveValue('delay');
    expect(screen.getByLabelText('Node 2 ID')).toHaveFocus();
});

test('confirmation dialog focuses the safe action, traps Tab, closes with Escape, and restores focus', () => {
    renderOperator();
    const publish = screen.getByRole('button', { name: 'Review and publish' });
    publish.focus();
    fireEvent.click(publish);

    const cancel = screen.getByRole('button', { name: 'Go back' });
    const confirm = screen.getByRole('button', { name: 'Confirm publish' });
    expect(screen.getByRole('dialog', { name: 'Confirm publish' })).toBeInTheDocument();
    expect(cancel).toHaveFocus();

    confirm.focus();
    fireEvent.keyDown(confirm, { key: 'Tab' });
    expect(cancel).toHaveFocus();
    fireEvent.keyDown(cancel, { key: 'Escape' });

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(publish).toHaveFocus();
    expect(post).not.toHaveBeenCalled();
});

test('cancellation confirmation discloses that an already claimed provider action may still complete', () => {
    renderOperator({ journeys: [{ ...journey, status: 'active' }] });

    fireEvent.click(screen.getByRole('button', { name: 'cancel' }));

    expect(screen.getByRole('dialog', { name: 'Confirm cancel' })).toHaveTextContent(
        'An action already claimed by a worker may still be sent or finish; a provider request cannot be recalled.',
    );
});

test('stale and connection failures are announced without implying a save', () => {
    renderOperator({ notice: { status: 'stale', code: 'draft_revision_conflict' } });
    expect(screen.getByRole('status')).toHaveTextContent('changed elsewhere');
    cleanup();

    post.mockImplementationOnce((_url: string, _data: unknown, options: { onError: () => void; onFinish: () => void }) => {
        options.onError();
        options.onFinish();
    });
    renderOperator();
    fireEvent.click(screen.getByRole('button', { name: /Save draft/ }));

    expect(screen.getByRole('status')).toHaveTextContent('Check permissions and connection');
    expect(screen.getByRole('status')).not.toHaveTextContent(/Journey state: saved/);
});

test('permission and timeout HTTP failures get distinct accessible guidance', () => {
    post.mockImplementationOnce((_url: string, _data: unknown, options: { onHttpException: (response: { status: number; data: string; headers: Record<string, string> }) => boolean | void; onFinish: () => void }) => {
        options.onHttpException({ status: 403, data: '', headers: {} });
        options.onFinish();
    });
    renderOperator();
    fireEvent.click(screen.getByRole('button', { name: /Save draft/ }));
    expect(screen.getByRole('status')).toHaveTextContent('Permission denied for this workspace action');
    cleanup();

    post.mockImplementationOnce((_url: string, _data: unknown, options: { onHttpException: (response: { status: number; data: string; headers: Record<string, string> }) => boolean | void; onFinish: () => void }) => {
        options.onHttpException({ status: 504, data: '', headers: {} });
        options.onFinish();
    });
    renderOperator();
    fireEvent.click(screen.getByRole('button', { name: /Save draft/ }));
    expect(screen.getByRole('status')).toHaveTextContent('request timed out');
    expect(screen.getByRole('status')).toHaveTextContent('verify whether the change was saved');
});

test('timeline renders every supported execution outcome with a bounded empty state', () => {
    const states = ['queued', 'waiting', 'running', 'retried', 'succeeded', 'blocked', 'cancelled', 'exited', 'failed'];
    renderOperator({
        timeline: states.map((status, index) => ({
            id: `execution-${index}`, journey_id: journey.id, version: 1, status, revision: 1,
            history: [{ status, node_id: 'entry', at: '2026-09-29T10:00:00Z' }],
            created_at: '2026-09-29T10:00:00Z', updated_at: '2026-09-29T10:00:00Z',
        })),
    });

    for (const state of states) expect(screen.getByText(state[0].toUpperCase() + state.slice(1), { selector: 'span' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Execution timeline' })).toBeInTheDocument();
});

test('empty workspaces explain draft-only behavior and provide an accessible create control', () => {
    renderOperator({ journeys: [] });
    expect(screen.getByRole('heading', { name: 'No journeys in this workspace' })).toBeInTheDocument();
    expect(screen.getByLabelText('Journey name')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create draft' })).toBeDisabled();
});

test('large graphs expose a text warning and disable further authoring', () => {
    const largeJourney = {
        ...journey,
        graph: { ...graph, nodes: Array.from({ length: 101 }, (_, index) => ({ id: `node-${index}`, type: 'end' as const })), edges: [] },
    };
    renderOperator({ journeys: [largeJourney] });

    expect(screen.getByRole('alert')).toHaveTextContent('exceeds the 100-node limit');
    expect(screen.getByLabelText('Add node')).toBeDisabled();
});

test('bounded editor collapses larger valid node lists and exposes an explicit expansion control', () => {
    const largeGraph: JourneyGraph = {
        schema_version: 1,
        nodes: [
            { id: 'entry', type: 'trigger', config: { event: 'customer.created' } },
            ...Array.from({ length: 38 }, (_, index) => ({ id: `wait-${index + 1}`, type: 'wait' as const, config: { seconds: 60 } })),
            { id: 'finish', type: 'end' },
        ],
        edges: [],
    };
    renderOperator({ journeys: [{ ...journey, graph: largeGraph }] });

    expect(screen.getByText('Showing the first 25 of 40 nodes.')).toBeInTheDocument();
    expect(screen.getByLabelText('Node 25 ID')).toBeInTheDocument();
    expect(screen.queryByLabelText('Node 26 ID')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Show all 40 nodes' }));
    expect(screen.getByLabelText('Node 40 ID')).toBeInTheDocument();
});

test('large transition lists render in bounded pages with explicit expansion and collapse', () => {
    const largeGraph: JourneyGraph = {
        schema_version: 1,
        nodes: [
            { id: 'entry', type: 'trigger', config: { event: 'customer.created' } },
            ...Array.from({ length: 12 }, (_, index) => ({ id: `wait-${index + 1}`, type: 'wait' as const, config: { seconds: 60 } })),
            { id: 'finish', type: 'end' },
        ],
        edges: [
            { from: 'entry', to: 'wait-1' },
            ...Array.from({ length: 11 }, (_, index) => ({ from: `wait-${index + 1}`, to: `wait-${index + 2}` })),
            { from: 'wait-12', to: 'finish' },
        ],
    };
    renderOperator({ journeys: [{ ...journey, graph: largeGraph }] });

    expect(screen.getByText('Showing 10 of 13 transitions.')).toBeInTheDocument();
    expect(screen.getByLabelText('Transition 10 from')).toHaveValue('wait-9');
    expect(screen.queryByLabelText('Transition 11 from')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Show 10 more transitions' }));
    expect(screen.getByLabelText('Transition 13 from')).toHaveValue('wait-12');
    expect(screen.getByText('Showing 13 of 13 transitions.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Show fewer transitions' }));
    expect(screen.queryByLabelText('Transition 11 from')).not.toBeInTheDocument();
});
