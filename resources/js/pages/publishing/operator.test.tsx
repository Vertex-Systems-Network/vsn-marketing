// @vitest-environment jsdom

import { render, screen } from '@testing-library/react';
import { expect, test, vi } from 'vitest';
import PublishingOperator from './operator';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
}));

test('renders immutable preview and backend-derived bulk safeguards without unauthorized mutation controls', () => {
    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            permissions={{ can_approve: false, can_send: true }}
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
                    approval_actions: {
                        approve: false,
                        reject: false,
                        revoke: false,
                        requires_snapshot_match: true,
                        requires_state_version_match: true,
                    },
                    bulk_safeguards: {
                        retry: {
                            candidate_count: 1,
                            affected_count: 1,
                            excluded_count: 1,
                            excluded_successful_count: 1,
                            permission_granted: true,
                            confirmation_required: true,
                            execution_enabled: false,
                            blocked_reason: 'preflight_only',
                        },
                    },
                },
            ]}
        />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Publishing operator' })).toBeTruthy();
    expect(screen.getByText('Canonical preview')).toBeTruthy();
    expect(screen.getAllByText('Partial success').length).toBeGreaterThan(0);
    expect(screen.getByText('Retry eligible')).toBeTruthy();
    expect(screen.getByLabelText('Bulk retry preflight')).toBeTruthy();
    expect(screen.getByText('execution locked')).toBeTruthy();
    expect(screen.queryByRole('button')).toBeNull();
});

test('shows only the server-authorized approval actions for an approval-queue campaign', () => {
    render(
        <PublishingOperator
            workspace={{ id: 'workspace-1', name: 'Approval Workspace', slug: 'approvals' }}
            permissions={{ can_approve: true, can_send: false }}
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 0, partial_success: 0 }}
            campaigns={[
                {
                    id: 'campaign-approval',
                    name: 'Approval campaign',
                    status: 'needs_approval',
                    state_version: 2,
                    snapshot: {
                        id: 'snapshot-approval',
                        version_number: 1,
                        content_version_id: 'content-approval',
                        snapshot_hash: 'aaaaaaaaaaaaaaaa',
                        target_set_hash: 'bbbbbbbbbbbbbbbb',
                        target_count: 1,
                        channels: ['email'],
                        intended_execution: {},
                        created_at: '2026-09-25T12:00:00Z',
                    },
                    approval: null,
                    schedule: null,
                    publication: {
                        execution_intent_id: null,
                        state: 'not_started',
                        retry_eligible_count: 0,
                        counts: { total: 1, succeeded: 0, pending: 1, in_progress: 0, failed: 0, cancelled: 0 },
                        targets: [{ target_id: 'target-1', channel: 'email', state: 'not_started', retry_eligible: false }],
                    },
                    approval_actions: {
                        approve: true,
                        reject: true,
                        revoke: false,
                        requires_snapshot_match: true,
                        requires_state_version_match: true,
                    },
                    bulk_safeguards: {
                        retry: {
                            candidate_count: 0,
                            affected_count: 0,
                            excluded_count: 1,
                            excluded_successful_count: 0,
                            permission_granted: false,
                            confirmation_required: true,
                            execution_enabled: false,
                            blocked_reason: 'permission_denied',
                        },
                    },
                },
            ]}
        />,
    );

    expect(screen.getByRole('button', { name: 'Approve' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Reject' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Revoke approval' })).toBeNull();
    expect(screen.getByText(/server revalidates workspace permission/i)).toBeTruthy();
});
