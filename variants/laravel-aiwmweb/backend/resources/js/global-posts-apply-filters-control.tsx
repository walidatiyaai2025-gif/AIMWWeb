import React, { type FormEvent } from 'react';
import { globalPostsEndpoint } from './global-posts-confirm-trash-control';
import { globalPostsFilterQuery } from './global-posts-reset-control';
import { useLocale } from './i18n';

export const GLOBAL_POSTS_APPLY_FILTERS_OPERATION_ID = 'AIMW-SYNC-7A726BAF5F';

export type GlobalPostsApplyFilterState = {
    search: string;
    siteId: string;
    status: string;
    perPage: number;
};

export const DEFAULT_GLOBAL_POSTS_FILTERS: GlobalPostsApplyFilterState = { search: '', siteId: '', status: 'all', perPage: 25 };

export function globalPostsFilterUrl(tenantSlug: string, filters: GlobalPostsApplyFilterState): string | null {
    const endpoint = globalPostsEndpoint(tenantSlug);
    if (!endpoint || !['all', 'publish', 'draft', 'pending', 'future', 'private'].includes(filters.status)) return null;
    if (![10, 25, 50, 100].includes(filters.perPage) || filters.search.length > 200) return null;
    const params = new URLSearchParams(globalPostsFilterQuery(filters));
    if (filters.siteId !== '') {
        if (!/^[0-9]+$/.test(filters.siteId)) return null;
        const id = Number(filters.siteId);
        if (!Number.isSafeInteger(id) || id <= 0) return null;
        params.set('site_id', String(id));
    }
    return endpoint + '?' + params.toString();
}

export function GlobalPostsApplyFiltersControl({
    value, sites, busy, siteSelectorAvailable, onChange, onApply,
}: {
    value: GlobalPostsApplyFilterState;
    sites: Array<{ id: number; name: string }>;
    busy: boolean;
    siteSelectorAvailable: boolean;
    onChange: (next: GlobalPostsApplyFilterState) => void;
    onApply: () => void;
}) {
    const { locale } = useLocale();
    const isArabic = locale === 'ar';
    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!busy) onApply();
    };
    return (
        <form className="toolbar-panel" role="search" aria-label={isArabic ? 'فلاتر مستكشف المقالات' : 'Posts Explorer filters'} onSubmit={submit}>
            <input aria-label={isArabic ? 'بحث المقالات' : 'Search posts'} value={value.search} maxLength={200}
                onChange={(event) => onChange({ ...value, search: event.target.value })}
                placeholder={isArabic ? 'العنوان أو الرابط المختصر أو المقتطف' : 'Title, slug, or excerpt'} />
            <select aria-label={isArabic ? 'موقع المقالات' : 'Post site'} value={value.siteId}
                disabled={!siteSelectorAvailable}
                onChange={(event) => onChange({ ...value, siteId: event.target.value })}>
                <option value="">{isArabic ? 'كل المواقع' : 'All sites'}</option>
                {sites.map((site) => <option key={site.id} value={String(site.id)}>{site.name}</option>)}
            </select>
            <select aria-label={isArabic ? 'حالة المقالات' : 'Post status'} value={value.status}
                onChange={(event) => onChange({ ...value, status: event.target.value })}>
                <option value="all">{isArabic ? 'كل الحالات' : 'All statuses'}</option>
                <option value="publish">{isArabic ? 'منشور' : 'Published'}</option>
                <option value="draft">{isArabic ? 'مسودة' : 'Draft'}</option>
                <option value="pending">{isArabic ? 'قيد المراجعة' : 'Pending'}</option>
                <option value="future">{isArabic ? 'مجدول' : 'Scheduled'}</option>
                <option value="private">{isArabic ? 'خاص' : 'Private'}</option>
            </select>
            <select aria-label={isArabic ? 'حجم الصفحة' : 'Page size'} value={value.perPage}
                onChange={(event) => onChange({ ...value, perPage: Number(event.target.value) })}>
                {[10, 25, 50, 100].map((size) => <option key={size} value={size}>{size}</option>)}
            </select>
            <button type="submit" className="btn primary" data-canonical-operation={GLOBAL_POSTS_APPLY_FILTERS_OPERATION_ID}
                disabled={busy} aria-busy={busy ? 'true' : 'false'}>
                {busy ? (isArabic ? 'جارٍ تطبيق الفلاتر…' : 'Applying filters…') : (isArabic ? 'تطبيق الفلاتر' : 'Apply filters')}
            </button>
        </form>
    );
}
