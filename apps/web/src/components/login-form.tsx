'use client';

import { useActionState } from 'react';
import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { login, type LoginState } from '@/lib/actions';

export function LoginForm() {
    const t = useTranslations('login');
    const [state, action, pending] = useActionState<LoginState, FormData>(login, {});

    return (
        <form action={action} className="flex flex-col gap-4" noValidate>
            {state.error && (
                <p role="alert" className="rounded-[12px] border border-error px-3 py-2 text-[13px] font-semibold text-ink">
                    {t(state.error)}
                </p>
            )}
            <Field id="email" label={t('email')} type="email" autoComplete="email" defaultValue={state.email} />
            <Field id="password" label={t('password')} type="password" autoComplete="current-password" />
            <button
                type="submit"
                disabled={pending}
                className="bg-brand-gradient h-12 rounded-full text-[15px] font-semibold text-white disabled:opacity-40"
            >
                {t('submit')}
            </button>
            <Link href="/signup" className="text-center text-[13px] font-semibold text-brand underline-offset-2 hover:underline">
                {t('noAccount')}
            </Link>
        </form>
    );
}

function Field({ id, label, type, autoComplete, defaultValue }: { id: string; label: string; type: string; autoComplete: string; defaultValue?: string }) {
    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-[12px] font-semibold text-ink-2">
                {label}
            </label>
            <input
                id={id}
                name={id}
                type={type}
                autoComplete={autoComplete}
                defaultValue={defaultValue}
                required
                className="h-12 rounded-[12px] border border-line-strong bg-field px-3.5 text-[14px] text-ink"
            />
        </div>
    );
}
