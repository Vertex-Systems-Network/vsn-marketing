// @vitest-environment jsdom
import '@testing-library/jest-dom/vitest';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, test } from 'vitest';
import BrandPreview from './brand-preview';

afterEach(cleanup);

const disclosures = { ownership: 'Data controller: Client A', consent: 'Recorded consent only.', billing: 'Billing is managed by VSN for Client A.' };

test('renders an accessible branded preview with system-owned disclosures', () => {
    render(<BrandPreview presentation={{ display_name: 'Acme Coffee', primary_color: '#aa3300', logo_url: 'https://cdn.example.test/logo.png',
        security_origin: 'https://app.vsn.test', disclosures }} />);
    expect(screen.getByRole('region', { name: 'Acme Coffee' })).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Acme Coffee logo' })).toHaveAttribute('src', 'https://cdn.example.test/logo.png');
    const footer = screen.getByRole('group', { name: 'Required disclosures' });
    expect(within(footer).getAllByRole('listitem')).toHaveLength(3);
    expect(screen.getByTestId('security-origin')).toHaveTextContent('https://app.vsn.test');
});

test('drops unsafe logo and colour values and falls back to an unbranded name', () => {
    render(<BrandPreview presentation={{ display_name: null, primary_color: 'red;x', logo_url: 'javascript:alert(1)',
        security_origin: 'https://app.vsn.test', disclosures }} />);
    expect(screen.getByRole('heading', { name: 'Unbranded workspace' })).toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
});
