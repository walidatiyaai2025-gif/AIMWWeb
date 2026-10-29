import React from 'react';
import { Link } from 'react-router-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const TAXONOMY_BACK_TO_SITES_OPERATION_ID = 'AIMW-TAXO-770DAA668D';

type ActiveSite = {
    id: number;
    name?: string;
    status?: string;
};

type SiteAwareFrontendContext = FrontendContext & {
    active_site?: ActiveSite | null;
};

function hasPermission(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function authoritativeTaxonomyBackToSitesHref(context: FrontendContext): string | null {
    const activeSite = (context as SiteAwareFrontendContext).active_site;

    // Source parity: this control is a recovery action for the Taxonomy "site not found"
    // state. A resolved site uses the normal taxonomy toolbar instead.
    if (activeSite !== null && activeSite !== undefined) return null;
    if (!hasPermission(context, 'tenant.view') || !hasPermission(context, 'sites.view')) return null;

    return tenantUrl(context.tenant.slug, '/sites');
}

export function TaxonomyBackToSitesControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const href = authoritativeTaxonomyBackToSitesHref(context);

    if (!href) return null;

    return (
        <nav
            className="toolbar-panel taxonomy-missing-site-navigation"
            aria-label={locale === 'ar' ? 'العودة إلى المواقع' : 'Taxonomy site recovery'}
            data-canonical-operation={TAXONOMY_BACK_TO_SITES_OPERATION_ID}
        >
            <Link className="btn compact" to={href}>
                ← {locale === 'ar' ? 'العودة إلى المواقع' : 'Back to sites'}
            </Link>
        </nav>
    );
}
