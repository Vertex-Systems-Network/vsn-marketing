import type { Metadata } from 'next';
import { getTranslations } from 'next-intl/server';
import { Logo } from '@/components/logo';
import { SignupForm } from '@/components/signup-form';

export async function generateMetadata(): Promise<Metadata> {
    return { title: (await getTranslations('signup'))('submit') };
}

export default async function SignupPage() {
    const t = await getTranslations('signup');

    return (
        <main className="flex min-h-screen items-center justify-center p-4">
            <div className="w-full max-w-[440px] rounded-[16px] border border-line bg-card p-8">
                <div className="mb-6 flex items-center gap-2.5">
                    <Logo />
                    <span className="text-[15px] font-bold">VSN Marketing</span>
                </div>
                <h1 className="mb-5 text-[22px] font-bold">{t('title')}</h1>
                <SignupForm />
            </div>
        </main>
    );
}
