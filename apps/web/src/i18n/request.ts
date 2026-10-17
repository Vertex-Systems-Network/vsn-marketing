import { cookies } from 'next/headers';
import { getRequestConfig } from 'next-intl/server';

export const locales = ['en', 'ur'] as const;
export type Locale = (typeof locales)[number];
export const rtlLocales: readonly Locale[] = ['ur'];

export function isLocale(value: string | undefined): value is Locale {
    return value !== undefined && (locales as readonly string[]).includes(value);
}

// Locale comes from the user's choice cookie (FR-I18N-03); the URL stays language-neutral.
export default getRequestConfig(async () => {
    const value = (await cookies()).get('vsn_locale')?.value;
    const locale: Locale = isLocale(value) ? value : 'en';

    return { locale, messages: (await import(`../../messages/${locale}.json`)).default };
});
