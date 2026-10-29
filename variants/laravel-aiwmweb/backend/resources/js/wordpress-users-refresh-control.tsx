import React, { useRef, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const WORDPRESS_USERS_REFRESH_OPERATION_ID = 'AIMW-SYNC-5A397204EF';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number } | null;
};

/**
 * A RefreshClickedAsync control may only reload users from the authenticated,
 * active tenant/site API; never trust arbitrary site IDs supplied by the caller.
 */
export function authorizedWordPressUsersRefreshEndpoint(
    context: FrontendContext,
    endpoint?: string,
): string | null {
    const siteId = (context as SiteAwareContext).active_site?.id;
    const slug = context.tenant.slug;
    if (!slug || !Number.isSafeInteger(siteId) || !siteId || siteId < 1) return null;

    if (!(context.permissions.includes('*') || (
        context.permissions.includes('tenant.view')
        && context.permissions.includes('sites.view')
    ))) return null;

    const expected = `/api/v1/tenants/${encodeURIComponent(slug)}/sites/${siteId}/users`;
    if (context.api['wp-users'] !== expected || endpoint !== expected) return null;
    return expected;
}

export function WordPressUsersRefreshControl({
    context,
    endpoint,
    busy = false,
    onReload,
}: {
    context: FrontendContext;
    endpoint?: string;
    busy?: boolean;
    onReload: () => Promise<void>;
}) {
    const { locale } = useLocale();
    const inflight = useRef(false);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const authorized = authorizedWordPressUsersRefreshEndpoint(context, endpoint);
    if (!authorized) return null;

    const reload = async () => {
        if (busy || inflight.current) return;
        inflight.current = true;
        setRefreshing(true);
        setError(null);
        try {
            // Consumer supplies React Query's refetch(), preserving the current
            // search/role/page query and performing a fresh WordPress REST read.
            // No local fake users or mutation are created.
            await onReload();
        } catch (cause) {
            setError(cause instanceof Error
                ? cause.message
                : (locale === 'ar' ? 'تعذّر تحديث المستخدمين.' : 'Could not refresh users.'));
        } finally {
            inflight.current = false;
            setRefreshing(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn"
                data-canonical-operation={WORDPRESS_USERS_REFRESH_OPERATION_ID}
                disabled={busy || refreshing}
                aria-busy={busy || refreshing}
                onClick={() => void reload()}
            >
                {busy || refreshing
                    ? (locale === 'ar' ? 'جارٍ تحديث المستخدمين…' : 'Refreshing users…')
                    : (locale === 'ar' ? 'تحديث المستخدمين' : 'Refresh users')}
            </button>
            {error && <p role="alert">{error}</p>}
        </>
    );
}
