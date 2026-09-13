import React, { useEffect, useState } from 'react';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const EXECUTION_CONNECT_SITE_OPERATION_ID = 'AIMW-AUTO-148B15F121';

type SiteReadState = 'idle' | 'loading' | 'empty' | 'nonempty' | 'error';

export function ExecutionConnectSiteControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [state, setState] = useState<SiteReadState>('idle');
    const endpoint = context.api.sites;
    const hasWildcard = context.permissions.includes('*');
    const canViewExecution = hasWildcard || context.permissions.includes('execution.view');
    const canManageSites = hasWildcard || context.permissions.includes('sites.manage');

    useEffect(() => {
        let cancelled = false;

        if (!canViewExecution || !canManageSites || !endpoint) {
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
    }, [canManageSites, canViewExecution, endpoint]);

    if (state !== 'empty') return null;

    return (
        <section
            className="panel empty-state execution-connect-site-empty"
            data-canonical-operation={EXECUTION_CONNECT_SITE_OPERATION_ID}
            aria-label={locale === 'ar' ? 'لا توجد مواقع في حسابك' : 'No sites in your account'}
        >
            <span className="workspace-kicker">{locale === 'ar' ? 'مركز التنفيذ' : 'EXECUTION CENTER'}</span>
            <strong>{locale === 'ar' ? 'لا توجد مواقع في حسابك' : 'No sites in your account'}</strong>
            <p>{locale === 'ar' ? 'أضف موقع WordPress أولًا لتظهر عملياته هنا.' : 'Connect a WordPress site first to view its operations here.'}</p>
            <a
                className="btn primary"
                href={tenantUrl(context.tenant.slug, '/sites/connect')}
                data-canonical-operation={EXECUTION_CONNECT_SITE_OPERATION_ID}
            >{locale === 'ar' ? 'إضافة موقع' : 'Connect site'}</a>
        </section>
    );
}
