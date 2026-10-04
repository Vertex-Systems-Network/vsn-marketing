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
