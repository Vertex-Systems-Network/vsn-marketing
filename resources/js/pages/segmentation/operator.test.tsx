// @vitest-environment jsdom

import '@testing-library/jest-dom/vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SegmentationOperator from './operator';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
afterEach(() => cleanup());
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post },
}));

const props = {
    proposal_available: true,
    fields: [{ id: 'contact.created_at', type: 'timestamp', operators: ['after'] }],
    registered_events: ['email.opened'],
    proposal_result: null,
    saved_segment: null,
    segments: [],
    preview_result: null,
    actions: { propose: '/proposals', store: '/versions', preview: '/preview', revise_base: '/segments' },
};

describe('SegmentationOperator', () => {
    beforeEach(() => post.mockReset());

    it('keeps proposal submission disabled when no approved provider route is available', () => {
        render(<SegmentationOperator {...props} proposal_available={false} />);
        expect(screen.getByRole('button', { name: 'Propose rules' })).toBeDisabled();
        expect(screen.getByText(/no approved AI route is configured/i)).toBeInTheDocument();
    });

    it('requires an editable definition, name, and explicit confirmation to save', () => {
        render(<SegmentationOperator {...props} />);
        const save = screen.getByRole('button', { name: 'Confirm and save draft' });
        expect(save).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Segment name'), { target: { value: 'Recent contacts' } });
        fireEvent.change(screen.getByLabelText('Structured definition (JSON)'), {
            target: { value: '{"schema_version":1,"subject":"contact","root":{"type":"group","operator":"all","children":[{"type":"attribute","field":"contact.created_at","operator":"after","value":"2026-01-01T00:00:00Z"}]}}' },
        });
        expect(save).toBeDisabled();

        fireEvent.click(screen.getByRole('checkbox'));
        expect(save).toBeEnabled();
        fireEvent.click(save);
        expect(post).toHaveBeenCalledWith('/versions', expect.objectContaining({
            name: 'Recent contacts',
            confirmed: true,
            definition: expect.objectContaining({ schema_version: 1 }),
        }), expect.any(Object));
    });

    it('explains that invalid proposals are never saved', () => {
        render(<SegmentationOperator {...props} proposal_result={{ status: 'invalid', code: 'unknown_field' }} />);
        expect(screen.getByText(/failed deterministic validation/i)).toBeInTheDocument();
    });
});

it('loads returned AST into the editable proposal review field', () => {
    const definition = {
        schema_version: 1,
        subject: 'contact',
        root: { type: 'group', operator: 'all', children: [{ type: 'attribute', field: 'company.domain', operator: 'equals', value: 'example.test' }] },
    };
    render(<SegmentationOperator {...props} proposal_result={{
        status: 'proposed',
        definition,
        explanation: 'ALL of',
        definition_hash: 'a'.repeat(64),
    }} />);
    expect(screen.getByLabelText('Structured definition (JSON)')).toHaveValue(JSON.stringify(definition, null, 2));
});

it('builds accessible visual rules and requests a bounded preview without confirmation', () => {
    render(<SegmentationOperator {...props} />);
    fireEvent.click(screen.getByRole('button', { name: 'Start visual rule builder' }));
    expect(screen.getByRole('group', { name: 'Audience rules' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add nested group' }));
    expect(screen.getByRole('group', { name: 'Nested rule group' })).toBeInTheDocument();
    fireEvent.click(screen.getAllByRole('button', { name: 'Add exclusion (NOT)' })[0]);
    expect(screen.getByText('Exclude contacts matching:')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Preview bounded count' }));
    expect(post).toHaveBeenCalledWith('/preview', expect.objectContaining({
        definition: expect.objectContaining({ schema_version: 1 }),
    }), expect.any(Object));
});

it('marks a returned count stale after a rule edit', () => {
    const preview = { status: 'fresh', count_kind: 'exact', count: 1,
        definition_hash: 'a'.repeat(64), definition_version: null,
        evaluated_at: '2026-09-26 12:00:00 UTC', source_freshness_at: null,
        eligibility_explanation: 'Send checks eligibility.' };
    render(<SegmentationOperator {...props} preview_result={preview} />);
    fireEvent.click(screen.getByRole('button', { name: 'Start visual rule builder' }));
    expect(screen.getByText(/This count is stale; preview again/)).toBeInTheDocument();
});

it('shows a safe unavailable count with no member identities', () => {
    render(<SegmentationOperator {...props} preview_result={{
        status: 'timeout_or_unavailable', count_kind: 'unavailable', count: null,
        definition_hash: 'a'.repeat(64), definition_version: null,
        evaluated_at: '2026-09-26 12:00:00 UTC', source_freshness_at: null,
        eligibility_explanation: 'Send checks eligibility.',
    }} />);
    expect(screen.getByText(/Count unavailable/)).toBeInTheDocument();
    expect(screen.getByText(/Member identities and personal details are hidden/)).toBeInTheDocument();
});

it('previews a selected immutable version by id and version', () => {
    const definition = { schema_version: 1, subject: 'contact', root: {
        type: 'group', operator: 'all', children: [{ type: 'attribute', field: 'contact.created_at', operator: 'is_set' }],
    } };
    render(<SegmentationOperator {...props} segments={[{
        id: 'segment-1', name: 'Existing', status: 'draft', published_version: null,
        latest_version: 2, latest_hash: 'a'.repeat(64), latest_definition: definition,
    }]} />);
    fireEvent.click(screen.getByRole('button', { name: 'Edit a new version' }));
    fireEvent.click(screen.getByRole('button', { name: 'Preview bounded count' }));
    expect(post).toHaveBeenCalledWith('/preview', expect.objectContaining({
        segment_id: 'segment-1', version: 2,
    }), expect.any(Object));
});

it('labels capped counts, unknown freshness, and the absence of delivery eligibility', () => {
    render(<SegmentationOperator {...props} preview_result={{
        status: 'large_audience', count_kind: 'capped', count: 250, count_lower_bound: 251,
        definition_hash: 'a'.repeat(64), definition_version: null, evaluated_at: '2026-09-26 12:00:00 UTC',
        source_freshness_at: null, eligibility_explanation: 'Consent and suppression are checked at send admission.',
    }} />);
    expect(screen.getByText(/At least 251 contacts/)).toBeInTheDocument();
    expect(screen.getByText(/Source freshness is unknown/)).toBeInTheDocument();
    expect(screen.getByText(/Consent and suppression are checked/)).toBeInTheDocument();
});
