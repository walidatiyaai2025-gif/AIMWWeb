import React, { useRef, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';
import { authorizedGlobalTaxonomyResetEndpoint } from './global-taxonomy-reset-control';

export const GLOBAL_TAXONOMY_APPLY_OPERATION_ID = 'AIMW-SYNC-B955324F04';
export type GlobalTaxonomyApplyFilters = {
    minimumUsage: 0 | 1 | 5 | 10 | 25;
    pageSize: 10 | 25 | 50 | 100;
};
export const defaultGlobalTaxonomyApplyFilters = (): GlobalTaxonomyApplyFilters => ({
    minimumUsage: 0,
    pageSize: 25,
});
export function globalTaxonomyApplyQuery(filters: GlobalTaxonomyApplyFilters): Record<string, string> {
    return { minimum_usage: String(filters.minimumUsage), per_page: String(filters.pageSize) };
}

/** The source ApplyAsync performs a read; it must never submit a taxonomy mutation. */
export function GlobalTaxonomyApplyControl({
    context, endpoint, value, busy, onChange, onApplyRequested,
}: {
    context: FrontendContext;
    endpoint?: string;
    value: GlobalTaxonomyApplyFilters;
    busy: boolean;
    onChange: (next: GlobalTaxonomyApplyFilters) => void;
    onApplyRequested: (trustedEndpoint: string) => Promise<void>;
}) {
    const { locale } = useLocale();
    const trustedEndpoint = authorizedGlobalTaxonomyResetEndpoint(context, endpoint);
    const inflight = useRef(false);
    const [applying, setApplying] = useState(false);
    const [error, setError] = useState<string | null>(null);
    if (!trustedEndpoint) return null;

    const apply = async () => {
        if (busy || inflight.current) return;
        inflight.current = true;
        setApplying(true);
        setError(null);
        try {
            await onApplyRequested(trustedEndpoint);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : String(reason));
        } finally {
            inflight.current = false;
            setApplying(false);
        }
    };

    return (
        <>
            <label htmlFor="taxonomy-minimum-usage">{locale === 'ar' ? 'الحد الأدنى للاستخدام' : 'Minimum usage'}
                <select id="taxonomy-minimum-usage" value={value.minimumUsage} disabled={busy || applying}
                    onChange={(event) => onChange({ ...value, minimumUsage: Number(event.currentTarget.value) as GlobalTaxonomyApplyFilters['minimumUsage'] })}>
                    {[0, 1, 5, 10, 25].map((n) => <option key={n} value={n}>{n === 0 ? '0' : n + '+'}</option>)}
                </select>
            </label>
            <label htmlFor="taxonomy-page-size">{locale === 'ar' ? 'حجم الصفحة' : 'Page size'}
                <select id="taxonomy-page-size" value={value.pageSize} disabled={busy || applying}
                    onChange={(event) => onChange({ ...value, pageSize: Number(event.currentTarget.value) as GlobalTaxonomyApplyFilters['pageSize'] })}>
                    {[10, 25, 50, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                </select>
            </label>
            <button type="button" className="btn primary" data-canonical-operation={GLOBAL_TAXONOMY_APPLY_OPERATION_ID}
                disabled={busy || applying} aria-busy={busy || applying} onClick={() => void apply()}>
                {busy || applying
                    ? (locale === 'ar' ? 'جارٍ تطبيق الفلاتر…' : 'Applying filters…')
                    : (locale === 'ar' ? 'تطبيق الفلاتر' : 'Apply filters')}
            </button>
            {error && <span role="alert">{error}</span>}
        </>
    );
}
