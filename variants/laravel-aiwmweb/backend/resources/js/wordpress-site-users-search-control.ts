import type { FrontendContext } from './core';

export const WORDPRESS_SITE_USERS_SEARCH_OPERATION_ID = 'AIMW-SYNC-4FDCFDA33F';

export const WORDPRESS_SITE_USER_ROLES = ['all', 'administrator', 'editor', 'author', 'contributor', 'subscriber'] as const;
export type WordPressSiteUserRole = typeof WORDPRESS_SITE_USER_ROLES[number];

export function normalizeWordPressSiteUserRole(candidate: string): WordPressSiteUserRole {
    return WORDPRESS_SITE_USER_ROLES.find((role) => role === candidate) ?? 'all';
}

// /sites/{site}/users is an authorized server-side redirect to /module/users?site={site}.
// Never infer the REST target from the query alone; it must exactly match the
// authenticated context's server-issued tenant/site API URL.
export function isAuthorizedWordPressSiteUsersSearch(
    context: FrontendContext,
    locationSearch: string,
): boolean {
    const siteText = new URLSearchParams(locationSearch).get('site');
    if (!siteText || !/^[1-9][0-9]*$/.test(siteText)) return false;

    const siteId = Number(siteText);
    if (!Number.isSafeInteger(siteId) || siteId < 1) return false;

    const tenant = context.tenant.slug;
    const can = (permission: string) => context.permissions.includes('*') || context.permissions.includes(permission);
    if (!tenant || !can('tenant.view') || !can('sites.view')) {
        return false;
    }

    return context.api['wp-users'] === `/api/v1/tenants/${tenant}/sites/${siteId}/users`;
}
