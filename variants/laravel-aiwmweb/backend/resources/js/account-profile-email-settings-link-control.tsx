import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ACCOUNT_PROFILE_EMAIL_SETTINGS_LINK_OPERATION = 'AIMW-EMAI-7A54150265';

export function AccountProfileEmailSettingsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    return (
        <nav
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'إجراءات إعدادات الحساب' : 'Account settings actions'}
        >
            <div className="toolbar-actions">
                <Link
                    className="btn"
                    to={tenantUrl(context.tenant.slug, '/account/email-settings')}
                    data-canonical-operation={ACCOUNT_PROFILE_EMAIL_SETTINGS_LINK_OPERATION}
                >
                    <span aria-hidden="true">✉</span>
                    {locale === 'ar' ? 'إعدادات بريد الداشبورد' : 'Dashboard email settings'}
                </Link>
            </div>
        </nav>
    );
}
