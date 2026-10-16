type Presentation = {
    display_name: string | null;
    primary_color: string | null;
    logo_url: string | null;
    security_origin: string;
    disclosures: { ownership: string; consent: string; billing: string };
};

/** Client-branded preview; security origin and disclosures are always system-rendered. */
export default function BrandPreview({ presentation }: { presentation: Presentation }) {
    const name = presentation.display_name ?? 'Unbranded workspace';
    const color = presentation.primary_color && /^#[0-9a-f]{6}$/.test(presentation.primary_color) ? presentation.primary_color : '#1f2937';

    return (
        <section aria-labelledby="brand-preview-title" className="rounded border p-4">
            <header className="flex items-center gap-3" style={{ borderBottom: `4px solid ${color}` }}>
                {presentation.logo_url?.startsWith('https://') && <img src={presentation.logo_url} alt={`${name} logo`} className="h-8" />}
                <h2 id="brand-preview-title">{name}</h2>
            </header>
            <p>
                Secure address: <span data-testid="security-origin">{presentation.security_origin}</span>
            </p>
            <div role="group" aria-label="Required disclosures">
                <ul>
                    <li>{presentation.disclosures.ownership}</li>
                    <li>{presentation.disclosures.consent}</li>
                    <li>{presentation.disclosures.billing}</li>
                </ul>
            </div>
        </section>
    );
}
