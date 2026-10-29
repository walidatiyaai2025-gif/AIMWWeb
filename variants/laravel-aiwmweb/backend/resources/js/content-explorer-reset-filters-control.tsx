import React, { useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_RESET_FILTERS_OPERATION_ID = 'AIMW-SYNC-627C90DC5C';

type SiteContext = FrontendContext & {
    active_site?: { id: number; name?: string } | null;
};

function canView(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

/** Fail closed unless the bootstrap-issued Explorer read belongs to this exact tenant and site. */
export function authoritativeContentExplorerResetEndpoint(context: FrontendContext): string | null {
    if (!canView(context, 'tenant.view') || !canView(context, 'sites.view')) return null;

    const siteId = (context as SiteContext).active_site?.id;
    const endpoint = context.api.explorer;
    if (!Number.isSafeInteger(siteId) || Number(siteId) <= 0 || !endpoint || !context.tenant.slug) return null;

    try {
        const url = new URL(endpoint, window.location.origin);
        const expected = '/api/v1/tenants/' + encodeURIComponent(context.tenant.slug)
            + '/sites/' + String(siteId) + '/explorer';
        if (url.origin !== window.location.origin || url.pathname !== expected || url.search || url.hash) {
            return null;
        }
        return url.pathname;
    } catch {
        return null;
    }
}

export function ContentExplorerResetFiltersControl({
    context,
    busy,
    onResetRequested,
}: {
    context: FrontendContext;
    busy: boolean;
    onResetRequested: (trustedEndpoint: string) => Promise<void>;
}) {
    const { locale } = useLocale();
    const endpoint = authoritativeContentExplorerResetEndpoint(context);
    const [resetting, setResetting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!endpoint) return null;

    const reset = async (): Promise<void> => {
        if (resetting || busy) return;
        setResetting(true);
        setError(null);
        try {
            await onResetRequested(endpoint);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : String(reason));
        } finally {
            setResetting(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn"
                data-canonical-operation={CONTENT_EXPLORER_RESET_FILTERS_OPERATION_ID}
                disabled={resetting || busy}
                aria-busy={resetting ? 'true' : 'false'}
                onClick={() => { void reset(); }}
            >
                {resetting
                    ? (locale === 'ar' ? 'جارٍ إعادة الضبط…' : 'Resetting…')
                    : (locale === 'ar' ? 'إعادة ضبط الفلاتر' : 'Reset filters')}
            </button>
            {error ? <span role="alert">{locale === 'ar' ? 'تعذر إعادة الضبط: ' : 'Reset failed: '}{error}</span> : null}
        </>
    );
}
