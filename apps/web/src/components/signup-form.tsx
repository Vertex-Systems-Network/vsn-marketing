'use client';

import { useActionState } from 'react';
import Link from 'next/link';
import { useTranslations } from 'next-intl';
import { signup, type SignupState } from '@/lib/actions';
import { FormField } from './form-field';

export function SignupForm() {
    const t = useTranslations('signup');
    const [state, action, pending] = useActionState<SignupState, FormData>(signup, {});
    const error = (field: string) => state.errors?.[field]?.[0];

    return (
        <form action={action} className="flex flex-col gap-4" noValidate>
            {state.error && (
                <p role="alert" className="rounded-[12px] border border-error px-3 py-2 text-[13px] font-semibold">
                    {t('unavailable')}
                </p>
            )}
            <FormField id="name" label={t('name')} autoComplete="name" defaultValue={state.values?.name} error={error('name')} />
            <FormField id="email" label={t('email')} type="email" autoComplete="email" defaultValue={state.values?.email} error={error('email')} />
            <FormField id="password" label={t('password')} type="password" autoComplete="new-password" error={error('password')} />
            <FormField id="workspace_name" label={t('workspace')} autoComplete="organization" defaultValue={state.values?.workspace_name} error={error('workspace_name')} />
            <button type="submit" disabled={pending} className="bg-brand-gradient h-12 rounded-full text-[15px] font-semibold text-white disabled:opacity-40">
                {t('submit')}
            </button>
            <Link href="/login" className="text-center text-[13px] font-semibold text-brand underline-offset-2 hover:underline">
                {t('haveAccount')}
            </Link>
        </form>
    );
}
