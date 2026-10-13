import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ACCOUNT_EMAIL_HISTORY_LINK_OPERATION_ID = 'AIMW-EMAI-0AA71A5EF6';
export const ACCOUNT_EMAIL_HISTORY_SOURCE_PATH = '/account/email-settings';
export const ACCOUNT_EMAIL_HISTORY_TARGET_PATH = '/email/history';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function accountEmailHistoryHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, ACCOUNT_EMAIL_HISTORY_TARGET_PATH);
}

export function canOpenAccountEmailHistory(context: FrontendContext): boolean {
    return hasPermission(context, 'tenant.manage')
        && hasPermission(context, 'diagnostics.view');
}

export function AccountEmailSettingsHistoryLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenAccountEmailHistory(context)) return null;

    return (
        <Link
            className="btn"
            to={accountEmailHistoryHref(context)}
            data-canonical-operation={ACCOUNT_EMAIL_HISTORY_LINK_OPERATION_ID}
            data-source-path={ACCOUNT_EMAIL_HISTORY_SOURCE_PATH}
            data-source-control-target={ACCOUNT_EMAIL_HISTORY_TARGET_PATH}
        >
            {locale === 'ar' ? 'سجل الإرسال' : 'Delivery history'}
        </Link>
    );
}
