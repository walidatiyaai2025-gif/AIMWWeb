import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const CONTENT_EXPLORER_SELECT_ALL_FILTERED_OPERATION_ID = 'AIMW-CONT-AC737C80BF';

type ExplorerEnvelope = {
    data?: Array<Record<string, unknown>>;
    total?: number;
    current_page?: number;
    last_page?: number;
};

export function contentExplorerSelectionKey(row: Record<string, unknown>): string | null {
    const type = String(row.type ?? '').trim();
    const remoteId = row.wordpress_id ?? row.remote_id ?? row.id;
    if (!type || remoteId === null || remoteId === undefined || String(remoteId).trim() === '') return null;
    return type + ':' + String(remoteId);
}

export function canonicalContentExplorerEndpoint(context: FrontendContext, endpoint?: string): string | null {
    if (!endpoint) return null;
    const url = new URL(endpoint, window.location.origin);
    if (url.origin !== window.location.origin || url.search || url.hash) return null;

    const prefix = '/api/v1/tenants/' + encodeURIComponent(context.tenant.slug) + '/sites/';
    const suffix = '/explorer';
    if (!url.pathname.startsWith(prefix) || !url.pathname.endsWith(suffix)) return null;

    const siteSegment = url.pathname.slice(prefix.length, -suffix.length);
    return /^\d+$/.test(siteSegment) ? url.pathname : null;
}

export async function loadAllFilteredExplorerKeys({
    context,
    endpoint,
    search,
    request = (url: string) => apiRequest<ExplorerEnvelope>(url),
}: {
    context: FrontendContext;
    endpoint?: string;
    search: string;
    request?: (url: string) => Promise<ExplorerEnvelope>;
}): Promise<Set<string>> {
    const canonical = canonicalContentExplorerEndpoint(context, endpoint);
    if (!canonical) throw new Error('The authoritative Content Explorer endpoint is unavailable.');

    const keys = new Set<string>();
    let page = 1;
    let lastPage = 1;

    do {
        const url = new URL(canonical, window.location.origin);
        url.searchParams.set('page', String(page));
        url.searchParams.set('per_page', '100');
        if (search.trim()) url.searchParams.set('search', search.trim());

        const payload = await request(url.pathname + url.search);
        const rows = Array.isArray(payload.data) ? payload.data : [];
        for (const row of rows) {
            const key = contentExplorerSelectionKey(row);
            if (key) keys.add(key);
        }

        lastPage = Math.max(1, Number(payload.last_page ?? 1));
        page += 1;
    } while (page <= lastPage);

    return keys;
}

export function ContentExplorerSelectAllFilteredControl({
    canEdit,
    selectedCount,
    total,
    onSelectAll,
}: {
    canEdit: boolean;
    selectedCount: number;
    total: number;
    onSelectAll: () => Promise<void>;
}) {
    const { locale } = useLocale();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!canEdit || selectedCount <= 0) return null;

    const allSelected = total > 0 && selectedCount >= total;
    const run = async () => {
        if (busy || allSelected) return;
        setBusy(true);
        setError(null);
        try {
            await onSelectAll();
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : (locale === 'ar' ? 'تعذر تحديد كل النتائج.' : 'Could not select all results.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="bulk-action-bar panel" aria-label={locale === 'ar' ? 'تحديد محتوى المستكشف' : 'Explorer content selection'}>
            <strong>{locale === 'ar' ? 'تم تحديد ' + selectedCount + ' عنصر' : selectedCount + ' items selected'}</strong>
            <button
                type="button"
                className="btn"
                data-canonical-operation={CONTENT_EXPLORER_SELECT_ALL_FILTERED_OPERATION_ID}
                disabled={busy || allSelected}
                aria-busy={busy ? 'true' : 'false'}
                onClick={() => void run()}
            >
                {allSelected
                    ? (locale === 'ar' ? 'تم تحديد كل النتائج' : 'All results selected')
                    : busy
                        ? (locale === 'ar' ? 'جارٍ تحديد كل النتائج…' : 'Selecting all results…')
                        : (locale === 'ar' ? 'تحديد كل النتائج' : 'Select all results')}
            </button>
            {error ? <span role="alert">{error}</span> : null}
        </section>
    );
}
