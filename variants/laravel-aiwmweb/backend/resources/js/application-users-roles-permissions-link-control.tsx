import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const APPLICATION_USERS_ROLES_PERMISSIONS_OPERATION_ID = 'AIMW-CONT-1A08B49164';

export function ApplicationUsersRolesPermissionsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewRoles = context.permissions.includes('*') || context.permissions.includes('roles.view');

    if (!canViewRoles) return null;

    return (
        <a
            className="btn"
            href={tenantUrl(context.tenant.slug, '/admin/roles-permissions')}
            data-canonical-operation={APPLICATION_USERS_ROLES_PERMISSIONS_OPERATION_ID}
        >
            {locale === 'ar' ? 'الأدوار والصلاحيات' : 'Roles & permissions'}
        </a>
    );
}
