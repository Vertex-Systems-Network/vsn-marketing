import type { NextConfig } from 'next';
import createNextIntlPlugin from 'next-intl/plugin';

const withNextIntl = createNextIntlPlugin('./src/i18n/request.ts');

const apiOrigin = process.env.VSN_API_ORIGIN ?? 'http://127.0.0.1:8000';

const config: NextConfig = {
    reactStrictMode: true,
    poweredByHeader: false,
    transpilePackages: ['@vsn/ui-tokens', '@vsn/api-client'],
    // The Laravel API serves /api/v1; the web app proxies it so Sanctum cookies stay first-party.
    async rewrites() {
        return [{ source: '/api/v1/:path*', destination: `${apiOrigin}/api/v1/:path*` }];
    },
    async headers() {
        return [
            {
                source: '/:path*',
                headers: [
                    { key: 'X-Content-Type-Options', value: 'nosniff' },
                    { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
                    { key: 'X-Frame-Options', value: 'DENY' },
                ],
            },
        ];
    },
};

export default withNextIntl(config);
