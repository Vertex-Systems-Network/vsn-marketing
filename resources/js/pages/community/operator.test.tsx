// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import CommunityOperator from './operator';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post },
}));

afterEach(() => {
    cleanup();
    post.mockReset();
});

const item = {
    id: 'item-1',
    provider_key: 'linkedin',
    type: 'comment',
    body: 'Please help with this post.',
    moderation_state: 'open',
    assigned_actor_id: 'actor-1',
    proposal_text: 'Draft response',
    proposal_state: 'proposed',
    received_at: '2026-10-02T10:00:00+00:00',
    source_key: 'source-hash',
    author_hash: 'author-hash',
    provenance_hash: 'provenance-hash',
};

test('renders an accessible tenant community workflow and keeps AI proposals separate from provider sending', () => {
    render(
        <CommunityOperator
            items={[item]}
            permissions={{ can_moderate: true, can_propose: true, can_approve_ai: true }}
            notice={null}
            actions={{ base: '/workspaces/workspace-1/community' }}
        />,
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Community inbox' })).toBeInTheDocument();
    expect(screen.getByRole('table', { name: 'Community inbox' })).toBeInTheDocument();
    expect(screen.getByText(/never sends to a provider/)).toBeInTheDocument();
    expect(screen.getByLabelText('Assignee actor for item-1')).toHaveValue('actor-1');
    expect(screen.getByLabelText('Draft response proposal for item-1')).toHaveValue('Draft response');
    expect(screen.getByRole('button', { name: 'Approve proposal' })).toBeEnabled();
});

test('posts moderation and explicit operator proposal approval to scoped routes', () => {
    render(
        <CommunityOperator
            items={[item]}
            permissions={{ can_moderate: true, can_propose: true, can_approve_ai: true }}
            notice={null}
            actions={{ base: '/workspaces/workspace-1/community' }}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Resolve' }));
    expect(post).toHaveBeenCalledWith(
        '/workspaces/workspace-1/community/item-1/moderate',
        { state: 'resolved' },
        expect.objectContaining({ preserveScroll: true, onFinish: expect.any(Function) }),
    );

    post.mock.calls[0][2].onFinish();
    fireEvent.click(screen.getByRole('button', { name: 'Approve proposal' }));
    expect(post).toHaveBeenLastCalledWith(
        '/workspaces/workspace-1/community/item-1/proposals/approve',
        {},
        expect.objectContaining({ preserveScroll: true, onFinish: expect.any(Function) }),
    );
});

test('keeps moderation and AI actions disabled without workspace authority', () => {
    render(
        <CommunityOperator
            items={[item]}
            permissions={{ can_moderate: false, can_propose: false, can_approve_ai: false }}
            notice={null}
            actions={{ base: '/workspaces/workspace-1/community' }}
        />,
    );

    expect(screen.getByRole('button', { name: 'Hide' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Resolve' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Assign' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Save AI proposal' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Approve proposal' })).toBeDisabled();
});
