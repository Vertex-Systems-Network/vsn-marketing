'use server';

import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { ApiError } from '@vsn/api-client';
import { isLocale } from '@/i18n/request';
import { revalidatePath } from 'next/cache';
import { currentWorkspace, serverClient, TOKEN_COOKIE } from './session';

// Secure cookies in production; VSN_INSECURE_COOKIES=1 only for local http E2E.
const secure = process.env.NODE_ENV === 'production' && process.env.VSN_INSECURE_COOKIES !== '1';

export type LoginState = { error?: 'invalid' | 'unavailable'; email?: string };
export type SignupState = { errors?: Record<string, string[]>; error?: 'unavailable'; values?: Record<string, string> };
export type InviteState = { error?: 'forbidden' | 'invalid' | 'unavailable'; sent?: string };

async function storeToken(token: string) {
    (await cookies()).set(TOKEN_COOKIE, token, { httpOnly: true, sameSite: 'lax', secure, path: '/', maxAge: 60 * 60 * 12 });
}

export async function login(_: LoginState, form: FormData): Promise<LoginState> {
    const email = String(form.get('email') ?? '');
    const password = String(form.get('password') ?? '');
    try {
        const token = await (await serverClient()).createAccessToken({ email, password, device_name: 'web' });
        await storeToken(token.token);
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

export async function signup(_: SignupState, form: FormData): Promise<SignupState> {
    const values = {
        name: String(form.get('name') ?? ''),
        email: String(form.get('email') ?? ''),
        workspace_name: String(form.get('workspace_name') ?? ''),
    };
    try {
        const result = await (await serverClient()).register({ ...values, password: String(form.get('password') ?? ''), device_name: 'web' });
        await storeToken(result.token);
    } catch (error) {
        if (error instanceof ApiError && error.status === 422) {
            return { errors: error.problem.errors ?? {}, values };
        }
        return { error: 'unavailable', values };
    }
    redirect('/dashboard');
}

export async function inviteMember(_: InviteState, form: FormData): Promise<InviteState> {
    const workspace = await currentWorkspace();
    if (workspace === null) return { error: 'forbidden' };
    const email = String(form.get('email') ?? '');
    try {
        await (await serverClient(workspace.id)).createInvite({ email, role_id: String(form.get('role_id') ?? '') });
    } catch (error) {
        if (error instanceof ApiError) return { error: error.status === 403 ? 'forbidden' : 'invalid' };
        return { error: 'unavailable' };
    }
    revalidatePath('/settings/team');
    return { sent: email };
}

export async function revokeInvite(form: FormData): Promise<void> {
    const workspace = await currentWorkspace();
    if (workspace !== null) {
        await (await serverClient(workspace.id)).revokeInvite(String(form.get('invite_id') ?? ''));
        revalidatePath('/settings/team');
    }
}
