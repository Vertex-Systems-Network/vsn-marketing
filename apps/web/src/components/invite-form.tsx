'use client';

import { useActionState } from 'react';
import { useTranslations } from 'next-intl';
import { inviteMember, type InviteState } from '@/lib/actions';

export function InviteForm({ roles }: { roles: { id: string; name: string }[] }) {
    const t = useTranslations('team');
    const [state, action, pending] = useActionState<InviteState, FormData>(inviteMember, {});

    return (
        <form action={action} className="flex flex-col gap-3" aria-labelledby="invite-heading">
            <h2 id="invite-heading" className="text-[15px] font-semibold">
                {t('inviteTitle')}
            </h2>
            <div aria-live="polite">
                {state.sent && <p className="text-[13px] font-semibold">{t('sent', { email: state.sent })}</p>}
                {state.error && (
                    <p role="alert" className="text-[13px] font-semibold text-error">
                        {t(state.error)}
                    </p>
                )}
            </div>
            <div className="flex flex-wrap items-end gap-3">
                <div className="flex min-w-[220px] flex-1 flex-col gap-1.5">
                    <label htmlFor="invite-email" className="text-[12px] font-semibold text-ink-2">
                        {t('email')}
                    </label>
                    <input id="invite-email" name="email" type="email" required autoComplete="off" className="h-12 rounded-[12px] border border-line-strong bg-field px-3.5 text-[14px]" />
                </div>
                <div className="flex min-w-[180px] flex-col gap-1.5">
                    <label htmlFor="invite-role" className="text-[12px] font-semibold text-ink-2">
                        {t('role')}
                    </label>
                    <select id="invite-role" name="role_id" required className="h-12 rounded-[12px] border border-line-strong bg-field px-3 text-[14px]">
                        {roles.map((role) => (
                            <option key={role.id} value={role.id}>
                                {role.name}
                            </option>
                        ))}
                    </select>
                </div>
                <button type="submit" disabled={pending || roles.length === 0} className="bg-brand-gradient h-12 rounded-full px-5 text-[15px] font-semibold text-white disabled:opacity-40">
                    {t('send')}
                </button>
            </div>
            <p className="text-[12px] text-muted">{t('privilegedNote')}</p>
        </form>
    );
}
