import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const MY_SESSIONS_ADMIN_LINK_OPERATION_ID = 'AIMW-CONT-2CDC790742';

export function MySessionsAdminLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canManageApplicationUsers =
        context.permissions.includes('*') || context.permissions.includes('members.manage');

    if (!canManageApplicationUsers) return null;

    return (
        <section
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'إدارة جلسات التطبيق' : 'Application session administration'}
            data-canonical-operation={MY_SESSIONS_ADMIN_LINK_OPERATION_ID}
        >
            <div className="toolbar-actions">
                <a
                    className="btn secondary"
                    href={tenantUrl(context.tenant.slug, '/admin/sessions')}
                    data-canonical-operation={MY_SESSIONS_ADMIN_LINK_OPERATION_ID}
                >
                    {locale === 'ar' ? 'إدارة كل الجلسات' : 'Manage all sessions'}
                </a>
            </div>
        </section>
    );
}
