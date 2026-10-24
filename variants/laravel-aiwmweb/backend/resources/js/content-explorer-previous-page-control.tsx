import React from 'react';
import { commonText, useLocale } from './i18n';

export const CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID = 'AIMW-CONT-A31317B378';

export function previousContentExplorerPage(page: number): number {
    if (!Number.isSafeInteger(page) || page <= 1) return 1;
    return page - 1;
}

export function ContentExplorerPagination({
    page,
    lastPage,
    onPage,
}: {
    page: number;
    lastPage: number;
    onPage: (page: number) => void;
}) {
    const { locale, text } = useLocale();
    if (lastPage <= 1) return null;

    const previous = () => {
        if (page <= 1) return;
        onPage(previousContentExplorerPage(page));
    };
    const next = () => {
        if (page >= lastPage) return;
        onPage(page + 1);
    };

    return (
        <nav className="pagination" aria-label={locale === 'ar' ? 'ترقيم صفحات المحتوى' : 'Content pagination'}>
            <button
                type="button"
                className="btn"
                data-canonical-operation={CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID}
                onClick={previous}
                disabled={page <= 1}
            >
                {text(commonText.previous)}
            </button>
            <span aria-live="polite">{locale === 'ar' ? `صفحة ${page} من ${lastPage}` : `Page ${page} of ${lastPage}`}</span>
            <button type="button" className="btn" onClick={next} disabled={page >= lastPage}>
                {text(commonText.next)}
            </button>
        </nav>
    );
}
