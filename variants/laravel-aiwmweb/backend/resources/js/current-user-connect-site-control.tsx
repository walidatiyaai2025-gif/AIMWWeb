import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_CONNECT_SITE_OPERATION_ID = 'AIMW-SITE-E3EA44AD3F';

type ActiveSite = {
    id: number;
    name: string;
    status?: string | null;
};

type ContextWithActiveSite = FrontendContext & {
    active_site?: ActiveSite | null;
};

type EmptyState = 'idle' | 'loading' | 'empty' | 'nonempty' | 'error';

export function CurrentUserConnectSiteControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);
    const [state, setState] = useState<EmptyState>('idle');
    const activeSite = (context as ContextWithActiveSite).active_site;
    const endpoint = context.api.sites;
    const canManage = context.permissions.includes('sites.manage');

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.user-chip'));
    }, []);

    useEffect(() => {
        let cancelled = false;

        if (!target || activeSite || !canManage || !endpoint) {
            setState('idle');
            return () => { cancelled = true; };
        }

        setState('loading');
        apiRequest<unknown>(endpoint)
            .then((payload) => {
                if (cancelled) return;
                if (!Array.isArray(payload)) {
                    setState('error');
                    return;
                }
                setState(payload.length === 0 ? 'empty' : 'nonempty');
            })
            .catch(() => {
                if (!cancelled) setState('error');
            });

        return () => { cancelled = true; };
    }, [activeSite, canManage, endpoint, target]);

    if (!target || state !== 'empty') return null;

    return createPortal(
        <a
            href={tenantUrl(context.tenant.slug, '/sites/connect')}
            className="workspace-empty current-user-connect-site-link"
            data-canonical-operation={CURRENT_USER_CONNECT_SITE_OPERATION_ID}
            aria-label={locale === 'ar' ? 'ربط موقعك الأول' : 'Connect your first site'}
        >
            <span aria-hidden="true">＋</span>
            <span>{locale === 'ar' ? 'ربط موقعك الأول' : 'Connect your first site'}</span>
        </a>,
        target,
    );
}
