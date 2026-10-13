import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

// Canonical account email schedules navigation.
export const ACCOUNT_EMAIL_SCHEDULES_LINK_OPERATION_ID = 'AIMW-EMAI-2E59E39808';
export const ACCOUNT_EMAIL_SCHEDULES_SOURCE_PATH = '/account/email-settings';
export const ACCOUNT_EMAIL_SCHEDULES_TARGET_PATH = '/email/schedules';

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function accountEmailSchedulesHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, ACCOUNT_EMAIL_SCHEDULES_TARGET_PATH);
}

export function canOpenAccountEmailSchedules(context: FrontendContext): boolean {
    return hasPermission(context, 'operations.manage');
}

export function AccountEmailSettingsSchedulesLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenAccountEmailSchedules(context)) return null;

    return (
        <Link
            className="btn"
            to={accountEmailSchedulesHref(context)}
            data-canonical-operation={ACCOUNT_EMAIL_SCHEDULES_LINK_OPERATION_ID}
            data-source-path={ACCOUNT_EMAIL_SCHEDULES_SOURCE_PATH}
            data-source-control-target={ACCOUNT_EMAIL_SCHEDULES_TARGET_PATH}
        >
            {locale === 'ar' ? 'جدولة ملخص الداشبورد' : 'Dashboard email schedules'}
        </Link>
    );
}
