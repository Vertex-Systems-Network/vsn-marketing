import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import { NextIntlClientProvider } from 'next-intl';
import { getLocale, getMessages } from 'next-intl/server';
import { rtlLocales, type Locale } from '@/i18n/request';
import './globals.css';

export const metadata: Metadata = {
    title: { default: 'VSN Marketing', template: '%s · VSN Marketing' },
    description: 'Email, social, ads and AI marketing in one place.',
};

export default async function RootLayout({ children }: { children: React.ReactNode }) {
    const locale = (await getLocale()) as Locale;
    const theme = (await cookies()).get('vsn_theme')?.value === 'dark' ? 'dark' : 'light';

    return (
        <html lang={locale} dir={rtlLocales.includes(locale) ? 'rtl' : 'ltr'} data-theme={theme}>
            <body className="min-h-screen bg-cloud font-sans text-[13px] text-ink antialiased">
                <NextIntlClientProvider messages={await getMessages()}>{children}</NextIntlClientProvider>
            </body>
        </html>
    );
}
