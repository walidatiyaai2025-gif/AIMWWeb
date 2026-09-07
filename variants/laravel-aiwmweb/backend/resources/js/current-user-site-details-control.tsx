import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CurrentUserConnectSiteControl } from './current-user-connect-site-control';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_SITE_DETAILS_OPERATION_ID = 'AIMW-SITE-D7DF8247B4';
export const CURRENT_USER_SITE_SETTINGS_OPERATION_ID = 'AIMW-SITE-9F9F2977B5';

type ActiveSite = {
    id: number;
    name: string;
    status?: string | null;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

export function CurrentUserSiteDetailsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.user-chip'));
    }, []);

    const activeSite = (context as ContextWithActiveSite).active_site;
    const canRenderSiteActions = Boolean(
        target
        && activeSite
        && Number.isSafeInteger(activeSite.id)
        && activeSite.id > 0
        && context.permissions.includes('sites.view')
        && context.api[`sites.detail.${activeSite.id}`] === `/api/tenants/${context.tenant.slug}/sites/${activeSite.id}`,
    );

    const details = canRenderSiteActions && target && activeSite
        ? createPortal(
            <a
                href={tenantUrl(context.tenant.slug, `/sites/${activeSite.id}`)}
                className="current-user-site-link"
                data-canonical-operation={CURRENT_USER_SITE_DETAILS_OPERATION_ID}
                aria-label={locale === 'ar' ? `فتح تفاصيل الموقع ${activeSite.name}` : `Open ${activeSite.name} site details`}
                title={activeSite.name}
            >
                <span aria-hidden="true">◉</span>
                <span>{activeSite.name}</span>
            </a>,
            target,
        )
        : null;

    const settings = canRenderSiteActions && target && activeSite
        ? createPortal(
            <a
                href={tenantUrl(context.tenant.slug, `/sites/${activeSite.id}/settings`)}
                className="current-user-site-settings-link"
                data-canonical-operation={CURRENT_USER_SITE_SETTINGS_OPERATION_ID}
                aria-label={locale === 'ar' ? `فتح إعدادات الموقع ${activeSite.name}` : `Open ${activeSite.name} site settings`}
            >
                <span aria-hidden="true">⚙</span>
                <span>{locale === 'ar' ? 'الإعدادات' : 'Settings'}</span>
            </a>,
            target,
        )
        : null;

    return (
        <>
            <CurrentUserConnectSiteControl context={context} />
            {details}
            {settings}
        </>
    );
}
