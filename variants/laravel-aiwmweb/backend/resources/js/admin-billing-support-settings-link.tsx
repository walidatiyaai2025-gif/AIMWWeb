import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ADMIN_BILLING_SUPPORT_SETTINGS_LINK_OPERATION_ID = 'AIMW-BILL-7DACB1EFDF';

export function AdminBillingSupportSettingsLink({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();

    return (
        <nav
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'تنقل دعم الفوترة' : 'Billing support navigation'}
            data-canonical-operation={ADMIN_BILLING_SUPPORT_SETTINGS_LINK_OPERATION_ID}
        >
            <div className="toolbar-actions">
                <Link
                    className="btn secondary"
                    to={tenantUrl(context.tenant.slug, '/settings')}
                    data-canonical-operation={ADMIN_BILLING_SUPPORT_SETTINGS_LINK_OPERATION_ID}
                >
                    <span aria-hidden="true">←</span>
                    {locale === 'ar' ? 'العودة للإعدادات' : 'Back to settings'}
                </Link>
            </div>
        </nav>
    );
}
