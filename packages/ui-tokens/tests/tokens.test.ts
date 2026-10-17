import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { color, contrastRatio, cssVar, type ColorToken, type ThemeName } from '../src/index';

const css = readFileSync(fileURLToPath(new URL('../src/tokens.css', import.meta.url)), 'utf8');

describe('design tokens (TASK-0104 AC-2, UX-GUIDELINES §5 A-01)', () => {
    it.each(['light', 'dark'] as ThemeName[])('%s theme meets WCAG 2.2 AA text contrast', (theme) => {
        const t = color[theme];
        for (const surface of ['surface/cloud', 'surface/field', 'surface/card'] as ColorToken[]) {
            expect(contrastRatio(t['brand/ink'], t[surface])).toBeGreaterThanOrEqual(4.5);
            expect(contrastRatio(t['brand/ink-2'], t[surface])).toBeGreaterThanOrEqual(4.5);
            expect(contrastRatio(t['text/muted'], t[surface])).toBeGreaterThanOrEqual(4.5);
        }
        // The focus outline uses brand/gradient-start (the cyan focus token is only an outer glow): ≥ 3:1 (1.4.11).
        expect(contrastRatio(t['brand/gradient-start'], t['surface/card'])).toBeGreaterThanOrEqual(3);
        expect(contrastRatio(t['brand/gradient-start'], t['surface/cloud'])).toBeGreaterThanOrEqual(3);
    });

    it('keeps tokens.css in sync with the TypeScript tokens for both themes', () => {
        for (const theme of ['light', 'dark'] as ThemeName[]) {
            for (const [token, value] of Object.entries(color[theme])) {
                expect(css).toContain(`${cssVar(token as ColorToken)}: ${value};`);
            }
        }
    });
});
