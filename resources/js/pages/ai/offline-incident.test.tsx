// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import OfflineIncidentPage, { type OfflineIncidentReview } from './offline-incident';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ Head: () => null, router: { post } }));
afterEach(() => { cleanup(); post.mockReset(); });

const review: OfflineIncidentReview = {
    status: 'held_offline', reason_code: 'unresolved_redteam_safety_case', run_id: 'offline-run',
    latest_audit_event_id: '11111111-1111-4111-8111-111111111111', audit_sequence: 2,
    case_count: 9, severity_counts: { critical: 2 }, category_counts: { goal_hijack: 1 },
    audit_history_integrity_checked: true, human_triage_required: true,
    synthetic_evidence_only: true, production_incident_verified: false, incident_resolved: false,
    recovery_performed: false, execution_authorized: false, promotion_authorized: false,
};

test('shows a safe unavailable state without triage controls', () => {
    render(<OfflineIncidentPage review={null} triage={null} triage_available={false} triage_action="/triage" notice={null} />);
    expect(screen.getByRole('alert')).toHaveTextContent('cannot be verified');
    expect(screen.queryByRole('button', { name: 'Escalate' })).not.toBeInTheDocument();
});

test('shows redacted counts and posts only the current audit reference and fixed decision', () => {
    render(<OfflineIncidentPage review={review} triage={{ status: 'not_recorded', triage_sequence: 0,
        current_review_triaged: false, recovery_review_status: 'blocked_unverified_external_effect',
        recovery_performed: false }} triage_available triage_action="/triage" notice={null} />);
    expect(screen.getByText('Human triage required. Recovery and production verification remain unconfirmed.')).toBeInTheDocument();
    expect(screen.getByText('Structural checks passed')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Escalate' }));
    expect(post).toHaveBeenCalledWith('/triage', {
        audit_event_id: review.latest_audit_event_id, decision: 'escalated',
    }, expect.objectContaining({ preserveScroll: true }));
    expect(screen.getByRole('button', { name: 'Record investigating' })).toBeDisabled();
});
