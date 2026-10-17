import 'server-only';
import { cookies } from 'next/headers';
import { ApiError, createClient } from '@vsn/api-client';

export const TOKEN_COOKIE = 'vsn_token';

const apiBase = `${process.env.VSN_API_ORIGIN ?? 'http://127.0.0.1:8000'}/api/v1`;

/** Server-side API client; the bearer token lives only in an httpOnly cookie (never in client JS). */
export async function serverClient(workspaceId?: string) {
    const token = (await cookies()).get(TOKEN_COOKIE)?.value;
    return createClient({ baseUrl: apiBase, token, workspaceId, credentials: 'omit' });
}

export async function currentUser() {
    try {
        return (await (await serverClient()).getMe()).data;
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            return null;
        }
        throw error;
    }
}

/** The active workspace: the user's choice cookie when still a member, else the first membership. */
export async function currentWorkspace() {
    const workspaces = (await (await serverClient()).listMyWorkspaces()).data;
    const chosen = (await cookies()).get('vsn_workspace')?.value;
    return workspaces.find((w) => w.id === chosen) ?? workspaces[0] ?? null;
}
