import { Head } from '@inertiajs/react';

export default function Welcome() {
    return (
        <>
            <Head title="Foundation" />
            <main className="mx-auto flex min-h-screen max-w-5xl items-center px-6 py-16">
                <section className="space-y-6">
                    <p className="text-sm font-medium uppercase tracking-[0.24em] text-neutral-400">VSN Marketing</p>
                    <h1 className="max-w-3xl text-4xl font-semibold tracking-tight sm:text-6xl">Provider-agnostic marketing infrastructure, built on deterministic guardrails.</h1>
                    <p className="max-w-2xl text-lg leading-8 text-neutral-400">PHASE-01 foundation runtime is active. Product modules will attach through canonical contracts, never concrete provider SDKs.</p>
                </section>
            </main>
        </>
    );
}
