import React from 'react';
import { Link } from 'react-router-dom';
import { BillingCancelPermanentlyControl } from './billing-cancel-permanently-control';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const BILLING_PROFILE_LINK_OPERATION = 'AIMW-BILL-67B6CF3962';
export const BILLING_EMAIL_SETTINGS_LINK_OPERATION = 'AIMW-BILL-092F59830B';

export function BillingProfileLink({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    return (
        <>
            <nav className="toolbar-panel" aria-label={locale === 'ar' ? 'تنقل الاشتراك والفوترة' : 'Subscription and billing navigation'}>
                <div className="toolbar-actions">
                    <Link
                        className="btn"
                        to={tenantUrl(context.tenant.slug, '/account/profile')}
                        data-canonical-operation={BILLING_PROFILE_LINK_OPERATION}
                    >
                        <span aria-hidden="true">←</span>
                        {locale === 'ar' ? 'حسابي' : 'My Account'}
                    </Link>
                    <Link
                        className="btn secondary"
                        to={tenantUrl(context.tenant.slug, '/account/email-settings')}
                        data-canonical-operation={BILLING_EMAIL_SETTINGS_LINK_OPERATION}
                    >
                        <span aria-hidden="true">✉</span>
                        {locale === 'ar' ? 'إعدادات البريد' : 'Email settings'}
                    </Link>
                </div>
            </nav>
            <BillingCancelPermanentlyControl context={context} />
        </>
    );
}
