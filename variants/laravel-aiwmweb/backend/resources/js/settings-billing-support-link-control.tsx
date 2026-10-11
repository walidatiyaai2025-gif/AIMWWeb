import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SETTINGS_BILLING_SUPPORT_LINK_OPERATION_ID = 'AIMW-BILL-81D0940A0A';

export function SettingsBillingSupportLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canManageSettings = context.permissions.includes('*') || context.permissions.includes('settings.manage');

    if (!canManageSettings) return null;

    return (
        <section
            className="toolbar-panel"
            aria-label={locale === 'ar' ? 'دعم الفوترة' : 'Billing support'}
            data-canonical-operation={SETTINGS_BILLING_SUPPORT_LINK_OPERATION_ID}
        >
            <div className="toolbar-actions">
                <a
                    className="btn"
                    href={tenantUrl(context.tenant.slug, '/admin/billing-support')}
                    data-canonical-operation={SETTINGS_BILLING_SUPPORT_LINK_OPERATION_ID}
                >
                    <span aria-hidden="true">PAY</span>
                    {locale === 'ar' ? 'دعم الفوترة' : 'Billing support'}
                </a>
            </div>
            <p className="muted">
                {locale === 'ar'
                    ? 'البحث والمصالحة والتدخلات المدققة'
                    : 'Search, reconciliation, and audited interventions'}
            </p>
        </section>
    );
}
