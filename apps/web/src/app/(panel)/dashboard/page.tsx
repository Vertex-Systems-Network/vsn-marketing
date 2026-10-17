import type { Metadata } from 'next';
import { getFormatter, getTranslations } from 'next-intl/server';
import { CheckCircle2, Mail, MousePointerClick, Plus, UserPlus } from 'lucide-react';
import { currentUser } from '@/lib/session';

export async function generateMetadata(): Promise<Metadata> {
    return { title: (await getTranslations('nav'))('dashboard') };
}

// Demo figures until TASK-0149 seeds the demo tenant and TASK-0136 exposes analytics over /api/v1.
const DEMO = { sent: 128_400, opens: 0.412, clicks: 9_820, followers: 1_276 };

export default async function DashboardPage() {
    const t = await getTranslations('dashboard');
    const format = await getFormatter();
    const user = await currentUser();
    const first = user?.name.split(/\s+/)[0] ?? '';

    const kpis = [
        { label: t('sent'), value: format.number(DEMO.sent), icon: Mail },
        { label: t('opens'), value: format.number(DEMO.opens, { style: 'percent', maximumFractionDigits: 1 }), icon: CheckCircle2 },
        { label: t('clicks'), value: format.number(DEMO.clicks), icon: MousePointerClick },
        { label: t('followers'), value: format.number(DEMO.followers), icon: UserPlus },
    ];

    return (
        <>
            <div className="flex items-center gap-2.5">
                <div className="flex flex-1 flex-col gap-0.5">
                    <h1 className="text-[22px] font-bold">{t('title', { name: first })}</h1>
                    <p className="text-[13px] text-muted">{t('subtitle')}</p>
                </div>
                <a href="/email/new" className="bg-brand-gradient flex h-12 items-center gap-2 rounded-full px-5 text-[15px] font-semibold text-white">
                    <Plus aria-hidden="true" size={18} />
                    {t('create')}
                </a>
            </div>

            <p role="note" className="rounded-[12px] border border-brand bg-card px-3.5 py-2.5 text-[13px] font-semibold">
                {t('demo')}
            </p>

            <section aria-labelledby="kpi-heading">
                <h2 id="kpi-heading" className="sr-only">
                    {t('kpis')}
                </h2>
                <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {kpis.map(({ label, value, icon: Icon }) => (
                        <li key={label} className="flex flex-col gap-2 rounded-[14px] border border-line bg-card p-4">
                            <span className="flex items-center gap-2 text-[12px] font-medium text-muted">
                                <Icon aria-hidden="true" size={16} className="text-brand" />
                                {label}
                            </span>
                            <span className="text-[26px] font-extrabold">{value}</span>
                        </li>
                    ))}
                </ul>
            </section>

            <div className="grid gap-3 lg:grid-cols-2">
                <section aria-labelledby="activity-heading" className="rounded-[14px] border border-line bg-card p-4">
                    <h2 id="activity-heading" className="mb-3 text-[15px] font-semibold">
                        {t('activity')}
                    </h2>
                    <p className="text-[13px] text-muted">{t('empty')}</p>
                </section>
                <section aria-labelledby="approvals-heading" className="rounded-[14px] border border-line bg-card p-4">
                    <h2 id="approvals-heading" className="mb-3 text-[15px] font-semibold">
                        {t('approvals')}
                    </h2>
                    <p className="text-[13px] text-muted">{t('empty')}</p>
                </section>
            </div>
        </>
    );
}
