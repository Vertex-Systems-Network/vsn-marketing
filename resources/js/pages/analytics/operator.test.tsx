// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen, act } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import Operator from './operator';
const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { post } }));
afterEach(() => { cleanup(); post.mockReset(); });
const props = { state: 'ready', reports: [], schedules: [], invalidated_reports: 0,
    report_kinds: ['counts', 'revenue'], default_start: '2026-10-02', default_end: '2026-10-03',
    notice: null, insight: null, explanation_available: false,
    actions: { generate: '/reports', schedules: '/schedules', base: '/analytics' } };
test('labels UTC inputs and keeps purpose and unavailable explanation actions disabled', () => {
    render(<Operator {...props} state="purpose_unavailable" />);
    expect(screen.getByLabelText('Start date (UTC, inclusive)')).toHaveValue('2026-10-02');
    expect(screen.getByRole('button', { name: 'Generate report' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Request validated explanation' })).toBeDisabled();
    expect(screen.getByRole('alert')).toHaveTextContent('purpose or retention');
});
test('renders source-specific provider aggregate evidence without cross-provider equivalence claims', () => {
    render(<Operator {...props} provider_engagement={[{
        id: 'provider-fact', provider_key: 'linkedin', metric: 'post.impressions', value: 42,
        definition: { provider_key: 'linkedin', provider_metric: 'post.impressions', unit: 'count', version: 1,
            semantics: 'provider_reported_aggregate', cross_provider_equivalent: false,
            limitation: 'Provider-defined impressions; not cross-provider equivalent.' },
        observed_at: '2026-10-02T10:00:00+00:00', received_at: '2026-10-02T10:05:00+00:00',
        receipt_lag_seconds: 300, delayed: true, provider_total_status: 'provider_reported_total',
        source_completeness: 'unknown', missing_provider_events: 'unknown', source_lineage_hash: 'hash', fingerprint: 'fingerprint',
    }]} />);
    expect(screen.getByRole('heading', { name: 'Provider engagement evidence' })).toBeInTheDocument();
    expect(screen.getByText('linkedin · post.impressions')).toBeInTheDocument();
    expect(screen.getByText('Provider-defined impressions; not cross-provider equivalent.')).toBeInTheDocument();
    expect(screen.getAllByText('unknown', { selector: 'dd' })).toHaveLength(2);
    expect(screen.getByText(/delayed receipt/)).toBeInTheDocument();
});

test('prevents repeated requests while busy and announces validation failures', () => {
    render(<Operator {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Generate report' }));
    expect(post).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('button', { name: 'Working…' })).toBeDisabled();
    act(() => { post.mock.calls[0][2].onError(); post.mock.calls[0][2].onFinish(); });
    expect(screen.getByRole('alert')).toHaveTextContent('failed validation');
    expect(screen.getByRole('button', { name: 'Generate report' })).toBeEnabled();
});
test('renders semantic measured values and separate inference with evidence details', () => {
    render(<Operator {...props} reports={[{ id: 'snapshot', fingerprint: 'hash', definition_hash: 'definition',
        definition: { kind: 'revenue', version: 1 }, start_utc: 'start', end_utc: 'end', receipt_cutoff_utc: 'cutoff',
        latest_receipt_utc: null, source_completeness: 'unknown', excluded: 1, lineage_count: 2,
        metrics: { 'USD.net': 101 }, quality: { unmatched_refund: 1 }, censored_subjects: 1 }]}
        insight={{ status: 'complete', output: { facts: [{ metric: 'USD.net', value: 101 }], inferences: ['source_coverage_unknown'] } }} />);
    expect(screen.getByRole('table')).toHaveAccessibleName('Measured values; revenue amounts are integer currency minor units');
    expect(screen.getByRole('rowheader', { name: 'USD.net' })).toBeInTheDocument();
    expect(screen.getByText('Measured USD.net: 101.')).toBeInTheDocument();
    expect(screen.getByText('Inference: source coverage unknown.')).toBeInTheDocument();
    expect(screen.getByText(/unmatched_refund: 1/)).toBeInTheDocument();
    expect(screen.getByText('Definition and evidence fingerprint')).toBeInTheDocument();
});

test('renders offline-only autonomy proposal without enabling outbound execution', () => {
    render(<Operator {...props} offline_autonomy_preview={{
        status: 'preview_ready', execution_authorized: false, run_id: 'safe-run', policy_version: 'v1',
        snapshot_sha256: 'a'.repeat(64),
        actions: [{ tool_id: 'analytics_read', effect: 'read', risk: 'R0', arguments_sha256: 'b'.repeat(64),
            source_ids: ['known-fact'], reason_code: 'metric_review' }],
        stages: { goal: 'validated', plan: 'validated', propose: 'offline_preview', execute: 'disabled',
            observe: 'unavailable', evaluate: 'not_run' },
    }} />);
    expect(screen.getByRole('heading', { name: 'Bounded AI marketing preview' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Execute actions (unavailable)' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Promote campaign (unavailable)' })).toBeDisabled();
});

test('posts selected analytics report only to the authorized offline preview endpoint', () => {
    const report = { id: '11111111-1111-4111-8111-111111111111', fingerprint: 'a'.repeat(64),
        definition_hash: 'def', definition: { kind: 'counts', version: 1 },
        start_utc: 'start', end_utc: 'end', receipt_cutoff_utc: 'cutoff', latest_receipt_utc: null,
        source_completeness: 'unknown', excluded: 0, lineage_count: 1, metrics: { count: 4 },
        quality: {}, censored_subjects: null };
    render(<Operator {...props} reports={[report]} autonomy_enabled
        actions={{ ...props.actions, autonomy_preview: '/workspaces/workspace/analytics/autonomy/preview' }} />);
    fireEvent.change(screen.getByLabelText('Review target (count)'), { target: { value: '15' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create offline preview' }));
    expect(post).toHaveBeenCalledWith('/workspaces/workspace/analytics/autonomy/preview',
        { report_id: report.id, target_count: '15' }, expect.anything());
});

test('disables offline preview submission when no authorized report or permission exists', () => {
    render(<Operator {...props} />);
    expect(screen.getByRole('button', { name: 'Create offline preview' })).toBeDisabled();
    expect(screen.getByText('AI preview permission or analytics purpose is unavailable.')).toBeInTheDocument();
});
