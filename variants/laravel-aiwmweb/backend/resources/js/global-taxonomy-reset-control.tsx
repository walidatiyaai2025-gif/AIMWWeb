import React, { useRef, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const GLOBAL_TAXONOMY_RESET_OPERATION_ID = 'AIMW-SYNC-5EE3F98A28';

type SiteContext = FrontendContext & { active_site?: { id: number } | null };

/** Only a tenant/site endpoint issued by the authenticated frontend bootstrap may be read. */
export function authorizedGlobalTaxonomyResetEndpoint(
    context: FrontendContext,
    endpoint?: string,
): string | null {
    const siteId = (context as SiteContext).active_site?.id;
    const allowed = context.permissions.includes('*')
        || (context.permissions.includes('tenant.view')
            && context.permissions.includes('sites.view')
            && context.permissions.includes('content.view'));
    if (!allowed || !context.tenant.slug || !Number.isSafeInteger(siteId) || !siteId || siteId < 1) return null;

    const expected = '/api/v1/tenants/' + encodeURIComponent(context.tenant.slug)
        + '/sites/' + String(siteId) + '/taxonomy';
    if (context.api.taxonomy !== expected || endpoint !== expected) return null;
    return expected;
}

export function GlobalTaxonomyResetControl({
    context,
    endpoint,
    busy,
    onResetRequested,
}: {
    context: FrontendContext;
    endpoint?: string;
    busy: boolean;
    onResetRequested: (authorizedEndpoint: string) => Promise<void>;
}) {
    const { locale } = useLocale();
    const authorizedEndpoint = authorizedGlobalTaxonomyResetEndpoint(context, endpoint);
    const inflight = useRef(false);
    const [resetting, setResetting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!authorizedEndpoint) return null;

    const reset = async () => {
        if (busy || inflight.current) return;
        inflight.current = true;
        setResetting(true);
        setError(null);
        try {
            await onResetRequested(authorizedEndpoint);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : String(reason));
        } finally {
            inflight.current = false;
            setResetting(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn"
                data-canonical-operation={GLOBAL_TAXONOMY_RESET_OPERATION_ID}
                disabled={busy || resetting}
                aria-busy={busy || resetting}
                onClick={() => void reset()}
            >
                {resetting
                    ? (locale === 'ar' ? 'جارٍ إعادة الضبط…' : 'Resetting…')
                    : (locale === 'ar' ? 'إعادة ضبط' : 'Reset')}
            </button>
            {error ? <span role="alert">{error}</span> : null}
        </>
    );
}
