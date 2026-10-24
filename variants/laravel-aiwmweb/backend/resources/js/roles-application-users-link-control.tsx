import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ROLES_APPLICATION_USERS_OPERATION_ID = 'AIMW-CONT-47C0477703';

export function RolesApplicationUsersLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewUsers = context.permissions.includes('*')
        || (context.permissions.includes('tenant.view') && context.permissions.includes('users.view'));

    if (!canViewUsers) return null;

    return (
        <a
            className="btn"
            href={tenantUrl(context.tenant.slug, '/admin/application-users')}
            data-canonical-operation={ROLES_APPLICATION_USERS_OPERATION_ID}
        >
            {locale === 'ar' ? 'مستخدمو التطبيق' : 'Application users'}
        </a>
    );
}
