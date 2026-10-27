import React from 'react';
import type { FrontendContext } from './core';

export const GLOBAL_MEDIA_RESET_OPERATION_ID = 'AIMW-SYNC-9B87CACA17';

export type GlobalMediaResetState = {
    search: string;
    mediaType: string;
    pageSize: 24;
    page: 1;
};

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

export function globalMediaResetState(): GlobalMediaResetState {
    return {
        search: '',
        mediaType: 'all',
        pageSize: 24,
        page: 1,
    };
}

export function authoritativeGlobalMediaEndpoint(context: FrontendContext): string | null {
    const siteId = (context as SiteAwareContext).active_site?.id;
    const endpoint = context.api.media;
    const hasContentView = context.permissions.includes('*') || context.permissions.includes('content.view');

    if (!hasContentView || !endpoint || !Number.isSafeInteger(siteId) || Number(siteId) <= 0) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expectedPath = '/api/v1/tenants/' + encodeURIComponent(context.tenant.slug)
            + '/sites/' + String(siteId) + '/media';

        if (resolved.origin !== window.location.origin) return null;
        if (resolved.pathname !== expectedPath || resolved.search || resolved.hash) return null;

        return resolved.pathname;
    } catch {
        return null;
    }
}

export function GlobalMediaResetControl({
    locale,
    busy,
    available,
    onReset,
}: {
    locale: 'en' | 'ar';
    busy: boolean;
    available: boolean;
    onReset: () => void;
}) {
    if (!available) return null;

    return (
        <button
            type="button"
            className="btn"
            data-canonical-operation={GLOBAL_MEDIA_RESET_OPERATION_ID}
            disabled={busy}
            aria-busy={busy ? 'true' : 'false'}
            onClick={onReset}
        >
            {busy
                ? (locale === 'ar' ? 'جارٍ إعادة الضبط…' : 'Resetting…')
                : (locale === 'ar' ? 'إعادة ضبط' : 'Reset')}
        </button>
    );
}
