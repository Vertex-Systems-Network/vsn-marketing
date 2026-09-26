import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import SegmentationOperator from './operator';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
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
    actions: { propose: '/proposals', store: '/versions' },
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
