import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_SITE_SETTINGS_OPERATION_ID = 'AIMW-SITE-9F9F2977B5';

type ActiveSite = {
    id: number;
    name: string;
    status?: string | null;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

export function CurrentUserSiteSettingsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.user-chip'));
    }, []);

    const activeSite = (context as ContextWithActiveSite).active_site;
    const canRender = Boolean(
        target
        && activeSite
        && Number.isSafeInteger(activeSite.id)
        && activeSite.id > 0
        && context.permissions.includes('sites.manage')
        && context.api[`sites.detail.${activeSite.id}`] === `/api/tenants/${context.tenant.slug}/sites/${activeSite.id}`,
    );

    if (!canRender || !target || !activeSite) return null;

    return createPortal(
        <a
            href={tenantUrl(context.tenant.slug, `/sites/${activeSite.id}/settings`)}
            className="current-user-site-link"
            data-canonical-operation={CURRENT_USER_SITE_SETTINGS_OPERATION_ID}
            aria-label={locale === 'ar' ? `فتح إعدادات الموقع ${activeSite.name}` : `Open ${activeSite.name} site settings`}
            title={locale === 'ar' ? 'إعدادات الموقع' : 'Site settings'}
        >
            <span aria-hidden="true">⚙</span>
            <span>{locale === 'ar' ? 'إعدادات الموقع' : 'Site settings'}</span>
        </a>,
        target,
    );
}
