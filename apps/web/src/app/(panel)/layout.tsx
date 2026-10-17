import { cookies } from 'next/headers';
import { redirect } from 'next/navigation';
import { AppShell } from '@/components/app-shell';
import { currentUser } from '@/lib/session';

export const dynamic = 'force-dynamic';

export default async function PanelLayout({ children }: { children: React.ReactNode }) {
    const user = await currentUser();
    if (user === null) {
        redirect('/login');
    }
    const collapsed = (await cookies()).get('vsn_sidebar')?.value === 'collapsed';

    return (
        <AppShell user={{ name: user.name }} initialCollapsed={collapsed}>
            {children}
        </AppShell>
    );
}
