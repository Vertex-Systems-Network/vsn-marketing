// @vitest-environment jsdom

import { fireEvent, render, screen } from '@testing-library/react';
import { expect, test, vi } from 'vitest';
import PublishingOperator from './operator';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post },
}));

const campaign = {
    id: 'campaign-1',
    name: 'Launch campaign',
    status: 'needs_approval',
    state_version: 7,
    snapshot: {
        id: 'snapshot-1',
        version_number: 3,
        content_version_id: 'content-version-1',
        snapshot_hash: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        target_set_hash: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        target_count: 2,
        channels: ['linkedin', 'instagram'],
        intended_execution: { mode: 'fixed_instant', timezone: 'UTC' },
        created_at: '2026-09-24T12:00:00Z',
    },
    approval: null,
    schedule: {
        strategy: 'fixed_instant',
        timezone_id: 'UTC',
        local_scheduled_at: '2026-09-24T13:00:00',
        resolved_at_utc: '2026-09-24T13:00:00Z',
    },
    publication: {
        execution_intent_id: 'intent-1',
        state: 'partial_success',
        retry_eligible_count: 1,
        counts: { total: 2, succeeded: 1, pending: 0, in_progress: 0, failed: 1, cancelled: 0 },
        targets: [
            { target_id: 'target-a', channel: 'linkedin', state: 'succeeded', retry_eligible: false },
            { target_id: 'target-b', channel: 'instagram', state: 'failed_retriable', retry_eligible: true },
        ],
    },
};

test('renders immutable preview and partial-success evidence without approval controls for read-only operators', () => {
    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1 }}
            permissions={{ can_approve: false }}
            bulk_result={null}
            campaigns={[campaign]}
        />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Publishing operator' })).toBeTruthy();
    expect(screen.getByText('Canonical preview')).toBeTruthy();
    expect(screen.getAllByText('Partial success').length).toBeGreaterThan(0);
    expect(screen.getByText('Retry eligible')).toBeTruthy();
    expect(screen.queryByRole('region', { name: 'Approval queue' })).toBeNull();
});

test('preflights selected approval candidates with immutable snapshot and state-version expectations', () => {
    post.mockClear();

    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1 }}
            permissions={{ can_approve: true }}
            bulk_result={null}
            campaigns={[campaign]}
        />,
    );

    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(screen.getByRole('button', { name: 'Preflight approve' }));

    expect(post).toHaveBeenCalledTimes(1);
    const [url, payload, options] = post.mock.calls[0];

    expect(url).toBe('/workspaces/workspace-123456789/publishing/approvals/bulk');
    expect(payload.operation).toBe('approve');
    expect(payload.confirmed).toBe(false);
    expect(payload.items).toEqual([
        { campaign_id: 'campaign-1', snapshot_id: 'snapshot-1', state_version: 7 },
    ]);
    expect(payload.batch_id).toMatch(/^[0-9a-f-]{36}$/i);
    expect(options).toEqual({ preserveScroll: true, preserveState: true });
});
