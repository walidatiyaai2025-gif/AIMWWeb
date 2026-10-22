import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ACCOUNT_PROFILE_EMAIL_SETTINGS_OPERATION_ID = 'AIMW-EMAI-7A54150265';
export const ACCOUNT_PROFILE_EMAIL_SETTINGS_SOURCE_PATH = '/account/profile';
export const ACCOUNT_PROFILE_EMAIL_SETTINGS_TARGET_PATH = '/account/email-settings';

export function accountProfileEmailSettingsHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, ACCOUNT_PROFILE_EMAIL_SETTINGS_TARGET_PATH);
}

export function canOpenAccountEmailSettings(context: FrontendContext): boolean {
    return context.permissions.includes('*') || context.permissions.includes('tenant.view');
}

export function AccountProfileEmailSettingsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    if (!canOpenAccountEmailSettings(context)) return null;

    return (
        <Link
            className="btn"
            to={accountProfileEmailSettingsHref(context)}
            data-canonical-operation={ACCOUNT_PROFILE_EMAIL_SETTINGS_OPERATION_ID}
            data-source-path={ACCOUNT_PROFILE_EMAIL_SETTINGS_SOURCE_PATH}
            data-source-control-target={ACCOUNT_PROFILE_EMAIL_SETTINGS_TARGET_PATH}
        >
            <span aria-hidden="true">✉</span>{' '}
            {locale === 'ar' ? 'إعدادات بريد الداشبورد' : 'Dashboard email settings'}
        </Link>
    );
}
