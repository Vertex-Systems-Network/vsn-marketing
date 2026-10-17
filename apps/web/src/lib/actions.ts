'use server';

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { ApiError } from '@vsn/api-client';
import { isLocale } from '@/i18n/request';
import { serverClient, TOKEN_COOKIE } from './session';

// Secure cookies in production; VSN_INSECURE_COOKIES=1 only for local http E2E.
const secure = process.env.NODE_ENV === 'production' && process.env.VSN_INSECURE_COOKIES !== '1';

export type LoginState = { error?: 'invalid' | 'unavailable'; email?: string };

export async function login(_: LoginState, form: FormData): Promise<LoginState> {
    const email = String(form.get('email') ?? '');
    const password = String(form.get('password') ?? '');
    try {
        const token = await (await serverClient()).createAccessToken({ email, password, device_name: 'web' });
        (await cookies()).set(TOKEN_COOKIE, token.token, {
            httpOnly: true,
            sameSite: 'lax',
            secure,
            path: '/',
            maxAge: 60 * 60 * 12,
        });
    } catch (error) {
        return { error: error instanceof ApiError && error.status === 422 ? 'invalid' : 'unavailable', email };
    }
    redirect('/dashboard');
}

export async function logout(): Promise<void> {
    try {
        await (await serverClient()).revokeCurrentAccessToken();
    } finally {
        (await cookies()).delete(TOKEN_COOKIE);
    }
    redirect('/login');
}

export async function setLocale(locale: string): Promise<void> {
    if (isLocale(locale)) {
        (await cookies()).set('vsn_locale', locale, { sameSite: 'lax', secure, path: '/', maxAge: 60 * 60 * 24 * 365 });
    }
}

export async function setTheme(theme: string): Promise<void> {
    if (theme === 'light' || theme === 'dark') {
        (await cookies()).set('vsn_theme', theme, { sameSite: 'lax', secure, path: '/', maxAge: 60 * 60 * 24 * 365 });
    }
}
