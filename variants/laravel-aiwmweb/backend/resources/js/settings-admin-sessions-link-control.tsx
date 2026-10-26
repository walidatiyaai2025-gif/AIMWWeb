import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_ADMIN_SESSIONS_LINK_OPERATION_ID = 'AIMW-CONT-4A64732FC6';

export function SettingsAdminSessionsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canManageApplicationUsers =
        context.permissions.includes('*') || context.permissions.includes('members.manage');

    if (!canManageApplicationUsers) return null;

    return (
        <section
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'إدارة جلسات التطبيق' : 'Application session administration'}
            data-canonical-operation={SETTINGS_ADMIN_SESSIONS_LINK_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">SEC</span>
                <strong>{locale === 'ar' ? 'إدارة الجلسات' : 'Session management'}</strong>
                <small>
                    {locale === 'ar'
                        ? 'المخزون والإبطال الإداري'
                        : 'Inventory and administrator revocation'}
                </small>
            </div>
            <div className="toolbar-actions">
                <a
                    className="btn secondary"
                    href={tenantUrl(context.tenant.slug, '/admin/sessions')}
                    data-canonical-operation={SETTINGS_ADMIN_SESSIONS_LINK_OPERATION_ID}
                >
                    {locale === 'ar' ? 'إدارة الجلسات' : 'Session management'}
                </a>
            </div>
        </section>
    );
}
