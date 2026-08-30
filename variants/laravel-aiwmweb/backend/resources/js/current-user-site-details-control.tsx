import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_SITE_DETAILS_OPERATION_ID = 'AIMW-SITE-D7DF8247B4';

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
    if (!target || !activeSite || !Number.isSafeInteger(activeSite.id) || activeSite.id <= 0) return null;
    if (!context.permissions.includes('sites.view')) return null;

    const expectedApi = `/api/tenants/${context.tenant.slug}/sites/${activeSite.id}`;
    if (context.api[`sites.detail.${activeSite.id}`] !== expectedApi) return null;

    const href = tenantUrl(context.tenant.slug, `/sites/${activeSite.id}`);

    return createPortal(
        <a
            href={href}
            className="current-user-site-link"
            data-canonical-operation={CURRENT_USER_SITE_DETAILS_OPERATION_ID}
            aria-label={locale === 'ar' ? `فتح تفاصيل الموقع ${activeSite.name}` : `Open ${activeSite.name} site details`}
            title={activeSite.name}
        >
            <span aria-hidden="true">◉</span>
            <span>{activeSite.name}</span>
        </a>,
        target,
    );
}
