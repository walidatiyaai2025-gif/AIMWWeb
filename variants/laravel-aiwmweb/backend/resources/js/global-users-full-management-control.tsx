import React from 'react';
import { resolveCapability, tenantUrl, type FrontendContext, type WorkspaceRoute } from './core';
import { useLocale } from './i18n';

export const GLOBAL_USERS_FULL_MANAGEMENT_OPERATION_ID = 'AIMW-CONT-B9E6EFF3DF';

type ActiveSite = {
    id: number;
    name: string;
    status?: string | null;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes(permission) || context.permissions.includes('*');
}

export function authoritativeGlobalUsersManagementHref(context: FrontendContext): string | null {
    const activeSite = (context as ContextWithActiveSite).active_site;
    const tenantSlug = context.tenant.slug;

    if (
        !tenantSlug
        || !activeSite
        || !Number.isSafeInteger(activeSite.id)
        || activeSite.id < 1
        || !hasPermission(context, 'tenant.view')
        || !hasPermission(context, 'sites.view')
    ) return null;

    const expectedSiteApi = `/api/tenants/${tenantSlug}/sites/${activeSite.id}`;
    const expectedUsersApi = `/api/v1/tenants/${tenantSlug}/sites/${activeSite.id}/users`;
    if (context.api[`sites.detail.${activeSite.id}`] !== expectedSiteApi) return null;
    if (context.api['wp-users'] !== expectedUsersApi) return null;

    return tenantUrl(tenantSlug, `/sites/${activeSite.id}/users`);
}

export function GlobalUsersFullManagementControl({
    context,
    route,
}: {
    context: FrontendContext;
    route: WorkspaceRoute;
}) {
    const { locale } = useLocale();

    if (route.key !== 'wp-users' || resolveCapability(context, route).state !== 'enabled') return null;

    const destination = authoritativeGlobalUsersManagementHref(context);
    if (!destination) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'إدارة مستخدمي WordPress' : 'WordPress user management'}>
            <div>
                <span className="workspace-kicker">WORDPRESS USERS</span>
                <strong>{locale === 'ar' ? 'إدارة المستخدمين' : 'User management'}</strong>
            </div>
            <div className="toolbar-actions">
                <a
                    className="btn primary"
                    href={destination}
                    data-canonical-operation={GLOBAL_USERS_FULL_MANAGEMENT_OPERATION_ID}
                >
                    {locale === 'ar' ? 'الإدارة الكاملة' : 'Full user management'}
                </a>
            </div>
        </nav>
    );
}
