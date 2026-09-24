// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { expect, test, vi } from 'vitest';
import PublishingOperator from './operator';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
}));

test('renders immutable preview and partial-success publication evidence without mutation controls', () => {
    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            summary={{ campaigns: 1, needs_approval: 0, scheduled: 1, partial_success: 1 }}
            campaigns={[
                {
                    id: 'campaign-1',
                    name: 'Launch campaign',
                    status: 'running',
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
                    approval: {
                        outcome: 'approved',
                        actor_role: 'campaign-approver',
                        occurred_at: '2026-09-24T12:01:00Z',
                        expires_at: null,
                        revoked: false,
                    },
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
                },
            ]}
        />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Publishing operator' })).toBeTruthy();
    expect(screen.getByText('Canonical preview')).toBeTruthy();
    expect(screen.getAllByText('Partial success').length).toBeGreaterThan(0);
    expect(screen.getByText('Retry eligible')).toBeTruthy();
    expect(screen.queryByRole('button')).toBeNull();
});
