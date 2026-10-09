// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test } from 'vitest';
import OfflineAutonomyPreviewPanel from './autonomy-preview';

afterEach(cleanup);

const fixture = {
    status: 'preview_ready', execution_authorized: false, run_id: 'offline-1', policy_version: 'v1',
    snapshot_sha256: 'a'.repeat(64),
    actions: [{
        tool_id: 'analytics_read', effect: 'read' as const, risk: 'R0' as const,
        arguments_sha256: 'b'.repeat(64), source_ids: ['approved-source'], reason_code: 'metric_review',
    }],
    stages: { goal: 'validated', plan: 'validated', propose: 'offline_preview',
        execute: 'disabled', observe: 'unavailable', evaluate: 'not_run' },
};

test('renders validated offline preview with accessible evidence and no operational controls', () => {
    render(<OfflineAutonomyPreviewPanel preview={fixture} />);
    expect(screen.getByRole('heading', { name: 'Bounded AI marketing preview' })).toBeInTheDocument();
    expect(screen.getByRole('table')).toHaveAccessibleName('Proposed read-only actions; none are authorized to run');
    expect(screen.getByRole('rowheader', { name: 'analytics_read' })).toBeInTheDocument();
    expect(screen.getByText('approved-source')).toBeInTheDocument();
    expect(screen.getByText('Disabled — offline preview only')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Execute actions (unavailable)' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Promote campaign (unavailable)' })).toBeDisabled();
});

test('shows fail-closed empty state and never enables actions', () => {
    render(<OfflineAutonomyPreviewPanel />);
    expect(screen.getByRole('status')).toHaveTextContent('No validated offline proposal');
    expect(screen.getByRole('button', { name: 'Execute actions (unavailable)' })).toBeDisabled();
});

test('rejects fabricated execution authority and unregistered write effects in presentation', () => {
    const unsafe = { ...fixture, execution_authorized: true };
    const { rerender } = render(<OfflineAutonomyPreviewPanel preview={unsafe} />);
    expect(screen.getByRole('status')).toHaveTextContent('All autonomous actions remain disabled');
    rerender(<OfflineAutonomyPreviewPanel preview={{ ...fixture, actions: [{
        ...fixture.actions[0], effect: 'send' as 'read',
    }] }} />);
    expect(screen.getByRole('status')).toHaveTextContent('All autonomous actions remain disabled');
    expect(screen.getByRole('button', { name: 'Promote campaign (unavailable)' })).toBeDisabled();
});
