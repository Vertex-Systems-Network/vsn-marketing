import type { Metadata } from 'next';
import { getFormatter, getTranslations } from 'next-intl/server';
import { ShieldAlert } from 'lucide-react';
import { InviteForm } from '@/components/invite-form';
import { revokeInvite } from '@/lib/actions';
import { currentWorkspace, serverClient } from '@/lib/session';

export async function generateMetadata(): Promise<Metadata> {
    return { title: (await getTranslations('team'))('title') };
}

// Mirrors DefaultRoles::PRIVILEGED on the server; the API is the enforcement point, this only hides options.
const PRIVILEGED = ['identity.manage', 'billing.manage', 'privacy.manage', 'agency.manage', 'provider.credentials.manage'];

export default async function TeamPage() {
    const t = await getTranslations('team');
    const format = await getFormatter();
    const workspace = await currentWorkspace();

    if (workspace === null || !workspace.permissions.includes('identity.manage')) {
        return (
            <div role="alert" className="flex items-center gap-3 rounded-[14px] border border-line bg-card p-6">
                <ShieldAlert aria-hidden="true" className="text-brand" />
                <p className="text-[14px] font-semibold">{t('denied')}</p>
            </div>
        );
    }

    const api = await serverClient(workspace.id);
    const [members, invites, roles] = await Promise.all([api.listMembers(), api.listInvites(), api.listRoles()]);
    const grantable = roles.data.filter(
        (role) => !role.permissions.some((p) => PRIVILEGED.includes(p)) && role.permissions.every((p) => workspace.permissions.includes(p)),
    );

    return (
        <>
            <div className="flex flex-col gap-0.5">
                <h1 className="text-[22px] font-bold">{t('title')}</h1>
                <p className="text-[13px] text-muted">{t('subtitle')}</p>
            </div>

            <section className="rounded-[14px] border border-line bg-card p-4">
                <InviteForm roles={grantable.map((r) => ({ id: r.id, name: r.name }))} />
            </section>

            <div className="grid gap-3 lg:grid-cols-2">
                <section aria-labelledby="members-heading" className="rounded-[14px] border border-line bg-card">
                    <h2 id="members-heading" className="px-4 pb-2.5 pt-4 text-[15px] font-semibold">
                        {t('members')}
                    </h2>
                    <table className="w-full text-start text-[13px]">
                        <thead className="bg-field text-[11px] font-semibold text-muted">
                            <tr>
                                <th scope="col" className="px-4 py-2 text-start">{t('email')}</th>
                                <th scope="col" className="px-4 py-2 text-start">{t('role')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {members.data.map((m) => (
                                <tr key={m.id} className="border-t border-line">
                                    <td className="px-4 py-2.5">
                                        <span className="block font-semibold">{m.name}</span>
                                        <span className="text-muted">{m.email}</span>
                                    </td>
                                    <td className="px-4 py-2.5">{m.roles.join(', ')}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>

                <section aria-labelledby="invites-heading" className="rounded-[14px] border border-line bg-card">
                    <h2 id="invites-heading" className="px-4 pb-2.5 pt-4 text-[15px] font-semibold">
                        {t('invites')}
                    </h2>
                    {invites.data.length === 0 ? (
                        <p className="px-4 pb-4 text-[13px] text-muted">{t('none')}</p>
                    ) : (
                        <ul>
                            {invites.data.map((i) => (
                                <li key={i.id} className="flex items-center gap-3 border-t border-line px-4 py-2.5 text-[13px]">
                                    <span className="flex-1">
                                        <span className="block font-semibold">{i.email}</span>
                                        <span className="text-muted">
                                            {i.role} · {t('expires', { date: format.dateTime(new Date(i.expires_at), { dateStyle: 'medium' }) })}
                                        </span>
                                    </span>
                                    <form action={revokeInvite}>
                                        <input type="hidden" name="invite_id" value={i.id} />
                                        <button type="submit" className="h-9 rounded-full border border-line-strong px-3.5 text-[12px] font-semibold">
                                            {t('revoke')}
                                        </button>
                                    </form>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <section aria-labelledby="roles-heading" className="rounded-[14px] border border-line bg-card p-4">
                <h2 id="roles-heading" className="mb-3 text-[15px] font-semibold">
                    {t('roles')}
                </h2>
                <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    {roles.data.map((role) => (
                        <li key={role.id} className="rounded-[12px] bg-field px-3.5 py-2.5">
                            <span className="block text-[13px] font-semibold">{role.name}</span>
                            <span className="text-[12px] text-muted">{t('permissions', { count: role.permissions.length })}</span>
                        </li>
                    ))}
                </ul>
            </section>
        </>
    );
}
