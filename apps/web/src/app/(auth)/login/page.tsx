import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { LoginForm } from '@/components/login-form';
import { Logo } from '@/components/logo';

export async function generateMetadata(): Promise<Metadata> {
    return { title: (await getTranslations('login'))('submit') };
}

export default async function LoginPage() {
    const t = await getTranslations('login');

    return (
        <main className="flex min-h-screen items-center justify-center p-4">
            <div className="w-full max-w-[400px] rounded-[16px] border border-line bg-card p-8">
                <div className="mb-6 flex items-center gap-2.5">
                    <Logo />
                    <span className="text-[15px] font-bold">VSN Marketing</span>
                </div>
                <h1 className="mb-5 text-[22px] font-bold">{t('title')}</h1>
                <LoginForm />
            </div>
        </main>
    );
}
