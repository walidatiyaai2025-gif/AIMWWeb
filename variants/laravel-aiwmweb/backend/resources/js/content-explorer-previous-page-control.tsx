import React from 'react';
import { commonText, useLocale } from './i18n';
import { SUPPORTED_PAGE_SIZES, visiblePaginationPages } from './canonical-pagination-window';

export const CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID = 'AIMW-CONT-A31317B378';

export function previousContentExplorerPage(page: number): number {
    if (!Number.isSafeInteger(page) || page <= 1) return 1;
    return page - 1;
}

export function ContentExplorerPagination({
    page,
    lastPage,
    onPage,
    total,
    pageSize,
    returnedCount,
    onPageSizeChange,
}: {
    page: number;
    lastPage: number;
    onPage: (page: number) => void;
    total?: number;
    pageSize?: number;
    returnedCount?: number;
    onPageSizeChange?: (size: number) => void;
}) {
    const { locale, text } = useLocale();
    const safeLastPage = Number.isSafeInteger(lastPage) ? Math.max(1, lastPage) : 1;
    const safePage = Number.isSafeInteger(page) ? Math.max(1, Math.min(safeLastPage, page)) : 1;
    if (safeLastPage <= 1 && total === undefined && !onPageSizeChange) return null;

    const previous = () => {
        if (safePage <= 1) return;
        onPage(previousContentExplorerPage(safePage));
    };
    const next = () => {
        if (safePage >= safeLastPage) return;
        onPage(safePage + 1);
    };

    const hasTotal = Number.isSafeInteger(total) && total! >= 0;
    const hasSize = Number.isSafeInteger(pageSize) && pageSize! > 0 && pageSize! <= 100;
    const count = Number.isSafeInteger(returnedCount) ? Math.max(0, Math.min(returnedCount!, pageSize ?? 0)) : pageSize ?? 0;
    const firstShown = hasTotal && hasSize && count > 0 && total! > (safePage - 1) * pageSize! ? (safePage - 1) * pageSize! + 1 : 0;
    const lastShown = hasTotal && hasSize && firstShown > 0 ? Math.min(total!, firstShown + count - 1) : 0;

    return (
        <nav className="pagination" aria-label={locale === 'ar' ? 'ترقيم صفحات المحتوى' : 'Content pagination'}>
            <div className="pagination-nav">
                <button type="button" className="btn" onClick={() => onPage(1)} disabled={safePage <= 1}>{locale === 'ar' ? 'الأولى' : 'First'}</button>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID}
                    onClick={previous}
                    disabled={safePage <= 1}
                >
                    {text(commonText.previous)}
                </button>
                <div className="pagination-numbers">
                    {visiblePaginationPages(safePage, safeLastPage).map((number) => (
                        <button type="button" key={number} className={number === safePage ? 'btn pagination-page active' : 'btn pagination-page'}
                            aria-current={number === safePage ? 'page' : undefined}
                            aria-label={locale === 'ar' ? `الصفحة ${number}` : `Page ${number}`}
                            disabled={number === safePage} onClick={() => onPage(number)}>{number}</button>
                    ))}
                </div>
                <span className="pagination-counter" aria-live="polite">{locale === 'ar' ? `صفحة ${safePage} من ${safeLastPage}` : `Page ${safePage} of ${safeLastPage}`}</span>
                <button type="button" className="btn" onClick={next} disabled={safePage >= safeLastPage}>{text(commonText.next)}</button>
                <button type="button" className="btn" onClick={() => onPage(safeLastPage)} disabled={safePage >= safeLastPage}>{locale === 'ar' ? 'الأخيرة' : 'Last'}</button>
            </div>
            {hasTotal && hasSize ? <span className="pagination-range" role="status">
                {locale === 'ar' ? `${firstShown}–${lastShown} من ${total}` : `${firstShown}–${lastShown} of ${total}`}
            </span> : null}
            {onPageSizeChange && hasSize ? (
                <label className="pagination-size">
                    <span>{locale === 'ar' ? 'صفوف لكل صفحة' : 'Rows per page'}</span>
                    <select aria-label={locale === 'ar' ? 'صفوف لكل صفحة' : 'Rows per page'} value={pageSize}
                        onChange={(event) => onPageSizeChange(Number(event.target.value))}>
                        {SUPPORTED_PAGE_SIZES.map((size) => <option value={size} key={size}>{size}</option>)}
                    </select>
                </label>
            ) : null}
        </nav>
    );
}
