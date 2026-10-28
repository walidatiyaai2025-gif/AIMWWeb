import React from 'react';
import { Link } from 'react-router-dom';
import { resolveCapability, tenantUrl, workspaceRoutes, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_SYNCHRONIZATION_CONNECT_SITE_OPERATION_ID = 'AIMW-SYNC-CC2B584BC4';

type SelectedSiteContext = FrontendContext & {
    active_site?: { id: number; name: string } | null;
};

const syncRoute = workspaceRoutes.find((route) => route.key === 'sync');
const onboardingRoute = workspaceRoutes.find((route) => route.key === 'site-connect');

export function globalSynchronizationConnectSiteDestination(context: FrontendContext): string | null {
    const has = (permission: string) => context.permissions.includes('*') || context.permissions.includes(permission);
    const slug = context.tenant.slug.trim();
    const activeSite = (context as SelectedSiteContext).active_site;

    // Source GlobalSynchronizationWorkspace: the action is visible only while
    // there is no selected site. It is navigation, not a site-creation claim.
    if (activeSite) return null;
    if (!slug || /[\\/]/.test(slug) || !context.tenants.some((tenant) => tenant.slug === slug)) return null;
    if (!syncRoute || !onboardingRoute || onboardingRoute.path !== '/sites/connect') return null;
    if (!['tenant.view', 'content.view', 'sync.view', 'sites.manage'].every(has)) return null;
    if (resolveCapability(context, syncRoute).state !== 'enabled') return null;
    if (context.capabilities['site-connect']?.state &&
        context.capabilities['site-connect']?.state !== 'enabled') return null;
    const expectedSites = `/api/tenants/${encodeURIComponent(slug)}/sites`;
    if (context.api.sites !== expectedSites) return null;

    // Do not require an already-connected pairing connector to navigate to
    // the onboarding page: first-time pairing happens after navigation.
    // Actual pairing is protected by its existing server-side permissions/API.
    return tenantUrl(slug, onboardingRoute.path);
}

export function GlobalSynchronizationConnectSiteLinkControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const destination = globalSynchronizationConnectSiteDestination(context);
    if (!destination) return null;

    return (
        <section className="panel empty-state global-sync-connect-site"
            data-canonical-operation={GLOBAL_SYNCHRONIZATION_CONNECT_SITE_OPERATION_ID}
            aria-label={locale === 'ar' ? 'لم يتم اختيار موقع' : 'No selected site'}>
            <strong>{locale === 'ar' ? 'اختر موقعًا لبدء المزامنة' : 'Select a site to synchronize'}</strong>
            <p>{locale === 'ar'
                ? 'يمكنك إضافة موقع جديد من شاشة المواقع ثم العودة إلى هنا.'
                : 'Add a site from the Sites screen, then return here to synchronize it.'}</p>
            <Link to={destination} className="btn primary"
                data-canonical-operation={GLOBAL_SYNCHRONIZATION_CONNECT_SITE_OPERATION_ID}>
                {locale === 'ar' ? 'إضافة موقع' : 'Connect a site'}
            </Link>
        </section>
    );
}
