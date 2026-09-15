import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const BILLING_PROFILE_LINK_OPERATION = 'AIMW-BILL-67B6CF3962';

export function BillingProfileLink({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    return (
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
            </div>
        </nav>
    );
}
