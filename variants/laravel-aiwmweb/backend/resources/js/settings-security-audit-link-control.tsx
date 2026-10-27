import React from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_SECURITY_AUDIT_LINK_OPERATION_ID = 'AIMW-CONT-ED2F1E1572';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function SettingsSecurityAuditLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canViewSettings = hasPermission(context, 'tenant.view');
    const canManageSettings = hasPermission(context, 'settings.manage');

    if (!canViewSettings || !canManageSettings) return null;

    return (
        <section
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'سجل التدقيق الأمني' : 'Security audit trail'}
            data-canonical-operation={SETTINGS_SECURITY_AUDIT_LINK_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">AUD</span>
                <strong>{locale === 'ar' ? 'سجل التدقيق الأمني' : 'Security audit trail'}</strong>
                <small>
                    {locale === 'ar'
                        ? 'الدخول والحسابات والأدوار والجلسات'
                        : 'Sign-in, accounts, roles, and sessions'}
                </small>
            </div>
            <div className="toolbar-actions">
                <a
                    className="btn"
                    href="/admin/security-audit"
                    data-canonical-operation={SETTINGS_SECURITY_AUDIT_LINK_OPERATION_ID}
                >
                    {locale === 'ar' ? 'فتح سجل التدقيق' : 'Open security audit'}
                </a>
            </div>
        </section>
    );
}
