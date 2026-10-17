/**
 * VSN design tokens. Source: Figma file JqukmwKhVyObh1SQ6gsKR0 → product file
 * 5lMnkndlKLHunSFGBJYhDN variable collection "VSN Tokens". Change values in Figma first.
 */
export const color = {
    light: {
        'brand/gradient-start': '#625ba8',
        'brand/gradient-mid': '#6188c6',
        'brand/gradient-end': '#5ac8d6',
        'brand/white': '#ffffff',
        'brand/ink': '#000000',
        'brand/ink-2': '#3f4245',
        'text/muted': '#636669',
        'surface/cloud': '#ebedf4',
        'surface/field': '#f4f5f9',
        'surface/card': '#ffffff',
        'surface/night': '#1a1c20',
        'line/light': '#eef0f5',
        'line/strong': '#dcdfe7',
        'state/error': '#d14b5a',
        focus: '#5ac8d6',
    },
    dark: {
        'brand/gradient-start': '#8a84cf',
        'brand/gradient-mid': '#7fa3dc',
        'brand/gradient-end': '#6fd3df',
        'brand/white': '#ffffff',
        'brand/ink': '#f4f5f9',
        'brand/ink-2': '#d6d8de',
        'text/muted': '#a3a6ad',
        'surface/cloud': '#1a1c20',
        'surface/field': '#24272d',
        'surface/card': '#202328',
        'surface/night': '#111215',
        'line/light': '#2d3037',
        'line/strong': '#3a3e46',
        'state/error': '#ef7a87',
        focus: '#6fd3df',
    },
} as const;

export type ThemeName = keyof typeof color;
export type ColorToken = keyof (typeof color)['light'];

export const radius = { field: 12, card: 14, panel: 16, pill: 999 } as const;
export const space = [4, 8, 10, 12, 16, 20, 24, 32] as const;
export const layout = { sidebarExpanded: 240, sidebarCollapsed: 72, contentMax: 1440 } as const;

/** CSS custom property name for a token, e.g. `brand/ink` → `--vsn-brand-ink`. */
export function cssVar(token: ColorToken): string {
    return `--vsn-${token.replace('/', '-')}`;
}

/** WCAG 2.x relative luminance contrast ratio between two hex colours. */
export function contrastRatio(foreground: string, background: string): number {
    const luminance = (hex: string): number => {
        const value = hex.replace('#', '');
        const channels = [0, 2, 4].map((i) => parseInt(value.slice(i, i + 2), 16) / 255);
        const [r, g, b] = channels.map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const [hi, lo] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
    return (hi + 0.05) / (lo + 0.05);
}
