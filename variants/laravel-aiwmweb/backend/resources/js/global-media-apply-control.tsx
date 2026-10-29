import React from 'react';
import type { FrontendContext } from './core';

export const GLOBAL_MEDIA_APPLY_OPERATION_ID = 'AIMW-SYNC-D805D5D9A8';
export type GlobalMediaType = 'all' | 'image' | 'video' | 'audio' | 'application';
export type GlobalMediaPageSize = 12 | 24 | 48 | 96;
export type GlobalMediaAppliedFilters = { mediaType: GlobalMediaType; pageSize: GlobalMediaPageSize };

export const defaultGlobalMediaFilters = (): GlobalMediaAppliedFilters => ({
    mediaType: 'all',
    pageSize: 24,
});

export function globalMediaApplyQuery(filters: GlobalMediaAppliedFilters): Record<string, string> {
    return {
        per_page: String(filters.pageSize),
        ...(filters.mediaType === 'all' ? {} : { mime_type: filters.mediaType }),
    };
}

export function authoritativeGlobalMediaApplyEndpoint(context: FrontendContext): string | null {
    const activeSite = (context as FrontendContext & { active_site?: { id: number } | null }).active_site;
    const siteId = activeSite?.id;
    const endpoint = context.api.media;
    if (
        !context.tenant?.slug
        || !(context.permissions.includes('*') || context.permissions.includes('content.view'))
        || !Number.isSafeInteger(siteId)
        || Number(siteId) <= 0
        || !endpoint
    ) return null;

    try {
        const resolved = new URL(endpoint, window.location.origin);
        const expected = '/api/v1/tenants/' + encodeURIComponent(context.tenant.slug)
            + '/sites/' + String(siteId) + '/media';
        if (resolved.origin !== window.location.origin || resolved.pathname !== expected
            || resolved.search || resolved.hash) return null;
        return expected;
    } catch {
        return null;
    }
}

export function GlobalMediaApplyControl({
    locale, value, busy, available, onTypeChange, onPageSizeChange,
}: {
    locale: 'en' | 'ar';
    value: GlobalMediaAppliedFilters;
    busy: boolean;
    available: boolean;
    onTypeChange: (value: GlobalMediaType) => void;
    onPageSizeChange: (value: GlobalMediaPageSize) => void;
}) {
    if (!available) return null;
    return (
        <>
            <label htmlFor="global-media-type">
                {locale === 'ar' ? 'نوع الوسائط' : 'Media type'}
                <select
                    id="global-media-type"
                    value={value.mediaType}
                    disabled={busy}
                    onChange={(event) => onTypeChange(event.currentTarget.value as GlobalMediaType)}
                >
                    <option value="all">{locale === 'ar' ? 'كل الأنواع' : 'All types'}</option>
                    <option value="image">{locale === 'ar' ? 'صور' : 'Images'}</option>
                    <option value="video">{locale === 'ar' ? 'فيديو' : 'Video'}</option>
                    <option value="audio">{locale === 'ar' ? 'صوت' : 'Audio'}</option>
                    <option value="application">{locale === 'ar' ? 'مستندات' : 'Documents'}</option>
                </select>
            </label>
            <label htmlFor="global-media-page-size">
                {locale === 'ar' ? 'حجم الصفحة' : 'Page size'}
                <select
                    id="global-media-page-size"
                    value={value.pageSize}
                    disabled={busy}
                    onChange={(event) => onPageSizeChange(Number(event.currentTarget.value) as GlobalMediaPageSize)}
                >
                    {[12, 24, 48, 96].map((size) => (
                        <option key={size} value={size}>{size}</option>
                    ))}
                </select>
            </label>
            <button
                type="submit"
                className="btn primary"
                data-canonical-operation={GLOBAL_MEDIA_APPLY_OPERATION_ID}
                disabled={busy}
                aria-busy={busy ? 'true' : 'false'}
            >
                {busy
                    ? (locale === 'ar' ? 'جارٍ تطبيق الفلاتر…' : 'Applying filters…')
                    : (locale === 'ar' ? 'تطبيق الفلاتر' : 'Apply filters')}
            </button>
        </>
    );
}
