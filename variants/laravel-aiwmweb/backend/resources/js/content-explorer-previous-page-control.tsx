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
    if (lastPage <= 1 && total === undefined && !onPageSizeChange) return null;

    const previous = () => {
        if (page <= 1) return;
        onPage(previousContentExplorerPage(page));
    };
    const next = () => {
        if (page >= lastPage) return;
        onPage(page + 1);
    };

    const hasTotal = Number.isSafeInteger(total) && total! >= 0;
    const hasSize = Number.isSafeInteger(pageSize) && pageSize! > 0;
    const firstShown = hasTotal && hasSize && total! > (page - 1) * pageSize! ? (page - 1) * pageSize! + 1 : 0;
    const lastShown = hasTotal && hasSize ? Math.min(total!, firstShown > 0 ? firstShown + Math.max(0, returnedCount ?? pageSize!) - 1 : 0) : 0;

    return (
        <nav className="pagination" aria-label={locale === 'ar' ? 'ترقيم صفحات المحتوى' : 'Content pagination'}>
            <div className="pagination-nav">
                <button type="button" className="btn" onClick={() => onPage(1)} disabled={page <= 1}>{locale === 'ar' ? 'الأولى' : 'First'}</button>
                <button
                    type="button"
                    className="btn"
                    data-canonical-operation={CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID}
                    onClick={previous}
                    disabled={page <= 1}
                >
                    {text(commonText.previous)}
                </button>
                <div className="pagination-numbers">
                    {visiblePaginationPages(page, lastPage).map((number) => (
                        <button type="button" key={number} className={number === page ? 'btn pagination-page active' : 'btn pagination-page'}
                            aria-current={number === page ? 'page' : undefined}
                            aria-label={locale === 'ar' ? `الصفحة ${number}` : `Page ${number}`}
                            disabled={number === page} onClick={() => onPage(number)}>{number}</button>
                    ))}
                </div>
                <span className="pagination-counter" aria-live="polite">{locale === 'ar' ? `صفحة ${page} من ${lastPage}` : `Page ${page} of ${lastPage}`}</span>
                <button type="button" className="btn" onClick={next} disabled={page >= lastPage}>{text(commonText.next)}</button>
                <button type="button" className="btn" onClick={() => onPage(lastPage)} disabled={page >= lastPage}>{locale === 'ar' ? 'الأخيرة' : 'Last'}</button>
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
