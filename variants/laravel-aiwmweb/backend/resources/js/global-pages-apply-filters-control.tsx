import React, { type FormEvent } from 'react';
import { globalPagesEndpoint } from './global-pages-confirm-trash-control';
import { useLocale } from './i18n';

export const GLOBAL_PAGES_APPLY_FILTERS_OPERATION_ID = 'AIMW-SYNC-8409FB077A';

export type GlobalPagesFilterState = {
    search: string;
    siteId: string;
    status: 'all' | 'publish' | 'draft' | 'pending' | 'private';
    perPage: number;
};

export const DEFAULT_GLOBAL_PAGES_FILTERS: GlobalPagesFilterState = {
    search: '',
    siteId: '',
    status: 'all',
    perPage: 25,
};

export function globalPagesFilterUrl(tenantSlug: string, filters: GlobalPagesFilterState): string | null {
    const endpoint = globalPagesEndpoint(tenantSlug);
    if (!endpoint) return null;
    if (!['all', 'publish', 'draft', 'pending', 'private'].includes(filters.status)) return null;
    if (![10, 25, 50, 100].includes(filters.perPage)) return null;

    const params = new URLSearchParams();
    params.set('page', '1');
    params.set('per_page', String(filters.perPage));
    if (filters.search.trim()) params.set('search', filters.search.trim());
    if (filters.status !== 'all') params.set('status', filters.status);
    if (filters.siteId !== '') {
        if (!/^[0-9]+$/.test(filters.siteId)) return null;
        const siteId = Number(filters.siteId);
        if (!Number.isSafeInteger(siteId) || siteId <= 0) return null;
        params.set('site_id', String(siteId));
    }
    return endpoint + '?' + params.toString();
}

export function GlobalPagesApplyFiltersControl({
    value, sites, busy, siteSelectorAvailable, onChange, onApply,
}: {
    value: GlobalPagesFilterState;
    sites: Array<{ id: number; name: string }>;
    busy: boolean;
    siteSelectorAvailable: boolean;
    onChange: (next: GlobalPagesFilterState) => void;
    onApply: () => void;
}) {
    const { locale } = useLocale();
    const isArabic = locale === 'ar';
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!busy) onApply();
    };

    return (
        <form className="toolbar-panel" role="search" aria-label={isArabic ? 'فلاتر مستكشف الصفحات' : 'Pages Explorer filters'} onSubmit={submit}>
            <input
                aria-label={isArabic ? 'بحث الصفحات' : 'Search pages'}
                value={value.search}
                maxLength={200}
                onChange={(event) => onChange({ ...value, search: event.target.value })}
                placeholder={isArabic ? 'العنوان أو الرابط المختصر' : 'Title or slug'}
            />
            <select
                aria-label={isArabic ? 'موقع الصفحات' : 'Page site'}
                value={value.siteId}
                disabled={!siteSelectorAvailable}
                onChange={(event) => onChange({ ...value, siteId: event.target.value })}
            >
                <option value="">{isArabic ? 'كل المواقع' : 'All sites'}</option>
                {sites.map((site) => (
                    <option key={site.id} value={String(site.id)}>{site.name}</option>
                ))}
            </select>
            <select
                aria-label={isArabic ? 'حالة الصفحات' : 'Page status'}
                value={value.status}
                onChange={(event) => onChange({ ...value, status: event.target.value as GlobalPagesFilterState['status'] })}
            >
                <option value="all">{isArabic ? 'كل الحالات' : 'All statuses'}</option>
                <option value="publish">{isArabic ? 'منشور' : 'Published'}</option>
                <option value="draft">{isArabic ? 'مسودة' : 'Draft'}</option>
                <option value="pending">{isArabic ? 'قيد المراجعة' : 'Pending'}</option>
                <option value="private">{isArabic ? 'خاص' : 'Private'}</option>
            </select>
            <select
                aria-label={isArabic ? 'حجم الصفحة' : 'Page size'}
                value={value.perPage}
                onChange={(event) => onChange({ ...value, perPage: Number(event.target.value) })}
            >
                {[10, 25, 50, 100].map((size) => (
                    <option key={size} value={size}>{size}</option>
                ))}
            </select>
            <button
                type="submit"
                className="btn primary"
                data-canonical-operation={GLOBAL_PAGES_APPLY_FILTERS_OPERATION_ID}
                disabled={busy}
                aria-busy={busy ? 'true' : 'false'}
            >
                {busy ? (isArabic ? 'جارٍ تطبيق الفلاتر…' : 'Applying filters…') : (isArabic ? 'تطبيق الفلاتر' : 'Apply filters')}
            </button>
        </form>
    );
}
