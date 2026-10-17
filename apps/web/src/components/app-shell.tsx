'use client';

import { useState, useTransition } from 'react';
import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useLocale, useTranslations } from 'next-intl';
import * as Tooltip from '@radix-ui/react-tooltip';
import clsx from 'clsx';
import {
    Bell,
    BarChart3,
    CircleHelp,
    House,
    Languages,
    LayoutTemplate,
    LogOut,
    Mail,
    Megaphone,
    Moon,
    PanelLeftClose,
    PanelLeftOpen,
    Search,
    Settings,
    Share2,
    ShoppingBag,
    Sparkles,
    Sun,
    Users,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import { logout, setLocale, setTheme } from '@/lib/actions';
import { Logo } from './logo';

type NavKey = 'dashboard' | 'email' | 'automations' | 'templates' | 'audience' | 'social' | 'ads' | 'ai' | 'analytics' | 'marketplace' | 'settings';

const NAV: { key: NavKey; href: string; icon: LucideIcon }[] = [
    { key: 'dashboard', href: '/dashboard', icon: House },
    { key: 'email', href: '/email', icon: Mail },
    { key: 'automations', href: '/automations', icon: Zap },
    { key: 'templates', href: '/templates', icon: LayoutTemplate },
    { key: 'audience', href: '/audience', icon: Users },
    { key: 'social', href: '/social', icon: Share2 },
    { key: 'ads', href: '/ads', icon: Megaphone },
    { key: 'ai', href: '/ai', icon: Sparkles },
    { key: 'analytics', href: '/analytics', icon: BarChart3 },
    { key: 'marketplace', href: '/marketplace', icon: ShoppingBag },
    { key: 'settings', href: '/settings', icon: Settings },
];

export function AppShell({ user, initialCollapsed, children }: { user: { name: string }; initialCollapsed: boolean; children: React.ReactNode }) {
    const t = useTranslations();
    const pathname = usePathname();
    const router = useRouter();
    const locale = useLocale();
    const [collapsed, setCollapsed] = useState(initialCollapsed);
    const [theme, setThemeState] = useState<'light' | 'dark'>(() =>
        typeof document !== 'undefined' && document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light',
    );
    const [, startTransition] = useTransition();
    const rtl = locale === 'ur';

    const toggleCollapsed = () => {
        const next = !collapsed;
        setCollapsed(next);
        document.cookie = `vsn_sidebar=${next ? 'collapsed' : 'expanded'}; path=/; max-age=31536000; samesite=lax`;
    };
    const toggleTheme = () => {
        const next = theme === 'dark' ? 'light' : 'dark';
        setThemeState(next);
        document.documentElement.dataset.theme = next;
        startTransition(() => setTheme(next));
    };
    const toggleLocale = () => startTransition(async () => {
        await setLocale(locale === 'ur' ? 'en' : 'ur');
        router.refresh();
    });
    const initials = user.name.split(/\s+/).filter(Boolean).map((w) => w[0]).join('').slice(0, 2).toUpperCase();
    const CollapseIcon = collapsed !== rtl ? PanelLeftOpen : PanelLeftClose;

    return (
        <Tooltip.Provider delayDuration={200}>
            <a href="#content" className="sr-only focus:not-sr-only focus:fixed focus:start-3 focus:top-3 focus:z-50 focus:rounded-[12px] focus:bg-card focus:px-3 focus:py-2">
                {t('app.skip')}
            </a>
            <div className="flex min-h-screen">
                <aside
                    data-testid="sidebar"
                    data-collapsed={collapsed}
                    className={clsx(
                        'sticky top-0 flex h-screen shrink-0 flex-col border-e border-line bg-card px-3 pb-4 pt-5 transition-[width] duration-200',
                        collapsed ? 'w-[72px]' : 'w-[240px]',
                    )}
                >
                    <div className={clsx('flex items-center gap-2.5 pb-1', collapsed ? 'justify-center' : 'ps-2.5')}>
                        <Logo />
                        {!collapsed && <span className="text-[15px] font-bold">{t('app.name')}</span>}
                    </div>
                    {!collapsed && <p className="ps-2.5 pb-3.5 text-[11px] font-semibold text-brand">{t('app.panel')}</p>}
                    <nav aria-label={t('nav.label')} className={clsx('flex flex-col gap-0.5', collapsed && 'pt-3.5')}>
                        {NAV.map(({ key, href, icon: Icon }) => {
                            const active = pathname === href || pathname.startsWith(`${href}/`);
                            const link = (
                                <Link
                                    href={href}
                                    aria-current={active ? 'page' : undefined}
                                    aria-label={collapsed ? t(`nav.${key}`) : undefined}
                                    className={clsx(
                                        'flex h-10 items-center gap-3 rounded-[12px] text-[13px] font-medium',
                                        collapsed ? 'justify-center' : 'px-3',
                                        active ? 'bg-cloud font-semibold text-ink' : 'text-ink-2 hover:bg-field',
                                    )}
                                >
                                    <Icon aria-hidden="true" size={20} strokeWidth={1.6} className={active ? 'text-brand' : undefined} />
                                    {!collapsed && <span>{t(`nav.${key}`)}</span>}
                                </Link>
                            );

                            return collapsed ? <Tip key={key} label={t(`nav.${key}`)} side={rtl ? 'left' : 'right'}>{link}</Tip> : <div key={key}>{link}</div>;
                        })}
                    </nav>
                    <div className="flex-1" />
                    <button
                        type="button"
                        onClick={toggleCollapsed}
                        aria-expanded={!collapsed}
                        aria-label={collapsed ? t('nav.expand') : t('nav.collapse')}
                        className={clsx('flex h-10 items-center gap-3 rounded-[12px] text-[13px] font-medium text-ink-2 hover:bg-field', collapsed ? 'justify-center' : 'px-3')}
                    >
                        <CollapseIcon aria-hidden="true" size={20} strokeWidth={1.6} />
                        {!collapsed && <span>{t('nav.collapse')}</span>}
                    </button>
                </aside>

                <div className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center gap-3.5 border-b border-line bg-card px-6 py-2.5">
                        <label className="flex flex-1 items-center gap-2 rounded-[12px] bg-field px-3.5 py-2 text-muted">
                            <Search aria-hidden="true" size={18} />
                            <span className="sr-only">{t('top.search')}</span>
                            <input type="search" placeholder={`${t('top.search')}  ⌘K`} className="w-full bg-transparent text-[13px] text-ink placeholder:text-muted focus-visible:outline-none" />
                        </label>
                        <IconButton label={t('top.notifications')} icon={Bell} />
                        <IconButton label={t('top.help')} icon={CircleHelp} />
                        <IconButton label={theme === 'dark' ? t('top.themeLight') : t('top.themeDark')} icon={theme === 'dark' ? Sun : Moon} onClick={toggleTheme} testId="theme-toggle" />
                        <button type="button" onClick={toggleLocale} aria-label={t('top.languageLabel')} className="flex h-9 items-center gap-1.5 rounded-full border border-line-strong px-3 text-[12px] font-semibold text-ink-2">
                            <Languages aria-hidden="true" size={16} />
                            {t('top.language')}
                        </button>
                        <span className="text-[13px] font-semibold text-ink-2">{user.name}</span>
                        <span aria-hidden="true" className="flex h-[30px] w-[30px] items-center justify-center rounded-full bg-cloud text-[11px] font-bold text-brand">
                            {initials}
                        </span>
                        <form action={logout}>
                            <IconButton label={t('top.logout')} icon={LogOut} type="submit" />
                        </form>
                    </header>
                    <main id="content" tabIndex={-1} className="flex flex-col gap-4 px-6 pb-8 pt-5">
                        {children}
                    </main>
                </div>
            </div>
        </Tooltip.Provider>
    );
}

function Tip({ label, side, children }: { label: string; side: 'left' | 'right'; children: React.ReactNode }) {
    return (
        <Tooltip.Root>
            <Tooltip.Trigger asChild>{children}</Tooltip.Trigger>
            <Tooltip.Portal>
                <Tooltip.Content side={side} sideOffset={8} className="rounded-[8px] bg-[var(--vsn-surface-night)] px-2.5 py-1.5 text-[12px] font-semibold text-white">
                    {label}
                </Tooltip.Content>
            </Tooltip.Portal>
        </Tooltip.Root>
    );
}

function IconButton({ label, icon: Icon, onClick, type = 'button', testId }: { label: string; icon: LucideIcon; onClick?: () => void; type?: 'button' | 'submit'; testId?: string }) {
    return (
        <button type={type} onClick={onClick} aria-label={label} title={label} data-testid={testId} className="flex h-9 w-9 items-center justify-center rounded-full text-ink-2 hover:bg-field">
            <Icon aria-hidden="true" size={20} strokeWidth={1.6} />
        </button>
    );
}
