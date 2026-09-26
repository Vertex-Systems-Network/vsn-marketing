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
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1, provider_attention: 0 }}
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
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1, provider_attention: 0 }}
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
    expect(options).toMatchObject({ preserveScroll: true, preserveState: true });
    expect(options.onFinish).toEqual(expect.any(Function));
});


test('confirms the exact server preflight batch and immutable item expectations', () => {
    post.mockClear();

    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1, provider_attention: 0 }}
            permissions={{ can_approve: true }}
            bulk_result={{
                batch_id: '11111111-1111-4111-8111-111111111111',
                operation: 'approve',
                confirmed: false,
                role_source: 'server_resolved_workspace_authority',
                counts: {
                    total: 1,
                    eligible: 1,
                    applied: 0,
                    already_applied: 0,
                    ineligible: 0,
                    conflict: 0,
                },
                results: [
                    {
                        campaign_id: 'campaign-1',
                        snapshot_id: 'snapshot-1',
                        expected_state_version: 7,
                        status: 'eligible',
                        reason: null,
                    },
                ],
            }}
            campaigns={[campaign]}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Confirm approve' }));

    expect(post).toHaveBeenCalledTimes(1);
    const [url, payload, options] = post.mock.calls[0];

    expect(url).toBe('/workspaces/workspace-123456789/publishing/approvals/bulk');
    expect(payload).toEqual({
        batch_id: '11111111-1111-4111-8111-111111111111',
        operation: 'approve',
        confirmed: true,
        reason: null,
        items: [
            { campaign_id: 'campaign-1', snapshot_id: 'snapshot-1', state_version: 7 },
        ],
    });
    expect(options).toMatchObject({ preserveScroll: true, preserveState: true });
    expect(options.onFinish).toEqual(expect.any(Function));
});

test('renders actionable provider attention without exposing raw provider evidence', () => {
    const providerCampaign = {
        ...campaign,
        publication: {
            ...campaign.publication,
            targets: [
                {
                    target_id: 'target-a',
                    channel: 'linkedin',
                    state: 'failed_retriable',
                    retry_eligible: true,
                    provider: {
                        status: 'permission_lost',
                        action: 'reauthorize_permissions',
                        retry_blocked: true,
                        retry_after_seconds: null,
                        next_probe_at: null,
                        evidence: 'canonical_provider_evidence',
                    },
                },
            ],
        },
    };

    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1, provider_attention: 1 }}
            permissions={{ can_approve: false }}
            bulk_result={null}
            campaigns={[providerCampaign]}
        />,
    );

    expect(screen.getByText('Provider attention')).toBeTruthy();
    expect(screen.getByText('Provider permission lost')).toBeTruthy();
    expect(screen.getByText('Next safe step: Reauthorize required provider permissions.')).toBeTruthy();
    expect(screen.getByText('Retry blocked')).toBeTruthy();
    expect(screen.queryByText('canonical_provider_evidence')).toBeNull();
});

test('announces canonical conflicts and prevents duplicate approval submission while busy', () => {
    post.mockClear();

    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1, provider_attention: 0 }}
            permissions={{ can_approve: true }}
            bulk_result={{
                batch_id: '22222222-2222-4222-8222-222222222222',
                operation: 'approve',
                confirmed: false,
                role_source: 'server_resolved_workspace_authority',
                counts: {
                    total: 1,
                    eligible: 1,
                    applied: 0,
                    already_applied: 0,
                    ineligible: 0,
                    conflict: 1,
                },
                results: [
                    {
                        campaign_id: 'campaign-1',
                        snapshot_id: 'snapshot-1',
                        expected_state_version: 7,
                        status: 'conflict',
                        reason: 'state_version_changed',
                    },
                ],
            }}
            campaigns={[campaign]}
        />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('canonical campaign state changed');

    const confirm = screen.getByRole('button', { name: 'Confirm approve' });
    fireEvent.click(confirm);

    expect(post).toHaveBeenCalledTimes(1);
    expect(confirm).toBeDisabled();
    expect(screen.getByText('Submitting approve decision…')).toBeTruthy();
});

test('renders explicit destructive guidance for reject confirmation', () => {
    render(
        <PublishingOperator
            workspace={{ id: 'workspace-123456789', name: 'VSN Workspace', slug: 'vsn' }}
            summary={{ campaigns: 1, needs_approval: 1, scheduled: 1, partial_success: 1, provider_attention: 0 }}
            permissions={{ can_approve: true }}
            bulk_result={{
                batch_id: '33333333-3333-4333-8333-333333333333',
                operation: 'reject',
                confirmed: false,
                role_source: 'server_resolved_workspace_authority',
                counts: {
                    total: 1,
                    eligible: 1,
                    applied: 0,
                    already_applied: 0,
                    ineligible: 0,
                    conflict: 0,
                },
                results: [
                    {
                        campaign_id: 'campaign-1',
                        snapshot_id: 'snapshot-1',
                        expected_state_version: 7,
                        status: 'eligible',
                        reason: null,
                    },
                ],
            }}
            campaigns={[campaign]}
        />,
    );

    const warning = screen.getByText(/Reject is destructive/);
    const confirm = screen.getByRole('button', { name: 'Confirm reject' });

    expect(warning).toBeTruthy();
    expect(confirm.getAttribute('aria-describedby')).toBe('bulk-reject-warning');
});

