import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const SITE_DETAILS_SETTINGS_LINK_OPERATION_ID = 'AIMW-AI-2B31C6BDAF';

export function siteDetailsSettingsHref(tenantSlug: string, siteId: string | undefined): string | null {
    if (!siteId || !/^[1-9]\d*$/.test(siteId)) return null;

    return `${tenantUrl(tenantSlug, '/settings')}?site=${encodeURIComponent(siteId)}`;
}

export function SiteDetailsSettingsLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const { siteId } = useParams();
    const target = siteDetailsSettingsHref(context.tenant.slug, siteId);
    const canViewSite = context.permissions.includes('*')
        || (context.permissions.includes('tenant.view') && context.permissions.includes('sites.view'));

    if (!canViewSite || !target) return null;

    return (
        <section
            className="toolbar-panel site-details-settings-navigation"
            aria-label={locale === 'ar' ? 'إعدادات الموقع المحدد' : 'Selected site settings navigation'}
            data-canonical-operation={SITE_DETAILS_SETTINGS_LINK_OPERATION_ID}
        >
            <div>
                <span className="workspace-kicker">{locale === 'ar' ? 'إعدادات الموقع' : 'SITE SETTINGS'}</span>
                <p>
                    {locale === 'ar'
                        ? 'افتح إعدادات الحساب مع تثبيت الموقع الحالي من سياق الحساب الموثوق.'
                        : 'Open settings with the current site pinned through the authoritative tenant context.'}
                </p>
            </div>
            <div className="toolbar-actions">
                <Link className="btn" to={target}>
                    <span aria-hidden="true">⚙</span>
                    {locale === 'ar' ? 'إعدادات الموقع' : 'Site settings'}
                </Link>
            </div>
        </section>
    );
}
