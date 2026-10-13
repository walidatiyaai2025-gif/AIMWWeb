import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const EMAIL_SCHEDULES_HISTORY_LINK_OPERATION_ID = 'AIMW-EMAI-EC34E40629';
export const EMAIL_SCHEDULES_HISTORY_SOURCE_PATH = '/email/schedules';
export const EMAIL_SCHEDULES_HISTORY_TARGET_PATH = '/email/history';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function emailSchedulesHistoryHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, EMAIL_SCHEDULES_HISTORY_TARGET_PATH);
}

export function canOpenEmailDeliveryHistory(context: FrontendContext): boolean {
    return hasPermission(context, 'operations.manage')
        && hasPermission(context, 'tenant.manage')
        && hasPermission(context, 'diagnostics.view');
}

export function EmailSchedulesHistoryLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenEmailDeliveryHistory(context)) return null;

    return (
        <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل جدولة البريد' : 'Email schedule navigation'}>
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={emailSchedulesHistoryHref(context)}
                    data-canonical-operation={EMAIL_SCHEDULES_HISTORY_LINK_OPERATION_ID}
                    data-source-path={EMAIL_SCHEDULES_HISTORY_SOURCE_PATH}
                    data-source-control-target={EMAIL_SCHEDULES_HISTORY_TARGET_PATH}
                >
                    {locale === 'ar' ? 'سجل الإرسال' : 'Delivery history'}
                </Link>
            </div>
        </nav>
    );
}
