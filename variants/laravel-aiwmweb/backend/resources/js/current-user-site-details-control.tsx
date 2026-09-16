import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { CurrentUserAboutBuildControl } from './current-user-about-build-control';
import { CurrentUserAccountProfileControl } from './current-user-account-profile-control';
import { CurrentUserConnectSiteControl } from './current-user-connect-site-control';
import { CurrentUserSettingsControl } from './current-user-settings-control';
import { CurrentUserSignOutControl } from './current-user-sign-out-control';
import { CurrentUserSystemHealthControl } from './current-user-system-health-control';
import { QuickActionsToggleControl } from './quick-actions-toggle-control';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_SITE_DETAILS_OPERATION_ID = 'AIMW-SITE-D7DF8247B4';
export const CURRENT_USER_CONTENT_EXPLORER_OPERATION_ID = 'AIMW-CONT-CEA27985CB';
export const CURRENT_USER_SITE_SETTINGS_OPERATION_ID = 'AIMW-SITE-9F9F2977B5';

type ActiveSite = {
    id: number;
    name: string;
    status?: string | null;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes(permission) || context.permissions.includes('*');
}

function hasAuthoritativeActiveSiteBinding(context: FrontendContext, activeSite: ActiveSite | null | undefined): activeSite is ActiveSite {
    return Boolean(
        activeSite
        && Number.isSafeInteger(activeSite.id)
        && activeSite.id > 0
        && context.api[`sites.detail.${activeSite.id}`] === `/api/tenants/${context.tenant.slug}/sites/${activeSite.id}`,
    );
}

export function authoritativeCurrentUserContentExplorerHref(context: FrontendContext): string | null {
    const activeSite = (context as ContextWithActiveSite).active_site;
    if (!hasAuthoritativeActiveSiteBinding(context, activeSite)) return null;
    if (!hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return null;

    const explicitExplorer = context.capabilities['explorer.view'] ?? context.capabilities.explorer;
    if (explicitExplorer && explicitExplorer.state !== 'enabled') return null;

    return `${tenantUrl(context.tenant.slug, '/explorer')}?site=${encodeURIComponent(String(activeSite.id))}`;
}

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

    const contentHref = authoritativeCurrentUserContentExplorerHref(context);
    const content = target && activeSite && contentHref
        ? createPortal(
            <a
                href={contentHref}
                className="current-user-site-content-link"
                data-canonical-operation={CURRENT_USER_CONTENT_EXPLORER_OPERATION_ID}
                aria-label={locale === 'ar' ? `فتح محتوى الموقع ${activeSite.name}` : `Open ${activeSite.name} content`}
            >
                <span aria-hidden="true">▦</span>
                <span>{locale === 'ar' ? 'المحتوى' : 'Content'}</span>
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
            <CurrentUserAccountProfileControl context={context} />
            <CurrentUserSettingsControl context={context} />
            <CurrentUserAboutBuildControl context={context} />
            <CurrentUserSystemHealthControl context={context} />
            <QuickActionsToggleControl context={context} />
            <CurrentUserSignOutControl />
            {details}
            {content}
            {settings}
        </>
    );
}
