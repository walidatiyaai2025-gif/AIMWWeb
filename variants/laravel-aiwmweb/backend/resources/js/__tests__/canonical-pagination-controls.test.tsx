import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Pagination } from '../components';
import { ContentExplorerPagination } from '../content-explorer-previous-page-control';
import { SUPPORTED_PAGE_SIZES, visiblePaginationPages, authoritativeCollectionTotal } from '../canonical-pagination-window';
import { LocaleProvider } from '../i18n';

afterEach(() => { cleanup(); window.localStorage.clear(); });

describe('Canonical server paging navigation (#1608)', () => {
    it('never fabricates a count from an unpaginated response', () => {
        expect(authoritativeCollectionTotal(undefined)).toBeUndefined();
        expect(authoritativeCollectionTotal('300')).toBeUndefined();
        expect(authoritativeCollectionTotal(-1)).toBeUndefined();
        expect(authoritativeCollectionTotal(0)).toBe(0);
        // A WordPress response without verified X-WP-Total cannot report its page count as a grand total.
        expect(authoritativeCollectionTotal(25, false)).toBeUndefined();
        expect(authoritativeCollectionTotal(0, false)).toBeUndefined();
        expect(authoritativeCollectionTotal(25, true)).toBe(25);
        expect(authoritativeCollectionTotal(25)).toBe(25);
        expect(visiblePaginationPages(5, 20)).toEqual([3, 4, 5, 6, 7]);
        expect(visiblePaginationPages(1, 3)).toEqual([1, 2, 3]);
        expect(visiblePaginationPages(20, 20)).toEqual([16, 17, 18, 19, 20]);
        expect(SUPPORTED_PAGE_SIZES).toEqual([10, 25, 50, 100]);
    });

    it('shows first, previous, page numbers, next, last and true server range', () => {
        const onPage = vi.fn();
        const change = vi.fn();
        render(<LocaleProvider><Pagination page={2} lastPage={6} onPage={onPage} total={137}
            pageSize={25} returnedCount={25} onPageSizeChange={change} /></LocaleProvider>);
        expect(screen.getByText('26–50 of 137')).toBeInTheDocument();
        expect(screen.getByText('Page 2 of 6')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Page 2' })).toHaveAttribute('aria-current', 'page');
        fireEvent.click(screen.getByRole('button', { name: 'First' }));
        fireEvent.click(screen.getByRole('button', { name: 'Last' }));
        fireEvent.click(screen.getByRole('button', { name: 'Page 4' }));
        expect(onPage.mock.calls.map(call => call[0])).toEqual([1, 6, 4]);
        fireEvent.change(screen.getByRole('combobox', { name: 'Rows per page' }), { target: { value: '50' } });
        expect(change).toHaveBeenCalledWith(50);
    });

    it('preserves disabled boundaries and renders an accurate zero-record state', () => {
        const go = vi.fn();
        render(<LocaleProvider><Pagination page={1} lastPage={1} onPage={go} total={0} pageSize={25}
            returnedCount={0} onPageSizeChange={vi.fn()} /></LocaleProvider>);
        expect(screen.getByText('0–0 of 0')).toBeInTheDocument();
        for (const name of ['First', 'Previous', 'Next', 'Last']) {
            const control = screen.getByRole('button', { name });
            expect(control).toBeDisabled();
            fireEvent.click(control);
        }
        expect(go).not.toHaveBeenCalled();
    });

    it('does not claim visible rows when a server page is temporarily empty', () => {
        const go = vi.fn();
        const { rerender } = render(<LocaleProvider><Pagination page={2} lastPage={6} onPage={go}
            total={137} pageSize={25} returnedCount={0} /></LocaleProvider>);
        expect(screen.getByText('0–0 of 137')).toBeInTheDocument();
        expect(screen.getByText('Page 2 of 6')).toBeInTheDocument();
        expect(screen.queryByText('26–25 of 137')).not.toBeInTheDocument();

        rerender(<LocaleProvider><Pagination page={2} lastPage={6} onPage={go}
            total={137} pageSize={25} returnedCount={1000} /></LocaleProvider>);
        expect(screen.getByText('26–50 of 137')).toBeInTheDocument();
    });

    it('does not fabricate visible-range counts when a server omits or corrupts returnedCount', () => {
        const props = { page: 2, lastPage: 6, onPage: vi.fn(), total: 137, pageSize: 25 };
        const { rerender } = render(<LocaleProvider><Pagination {...props} /></LocaleProvider>);
        expect(screen.getByText('Page 2 of 6')).toBeInTheDocument();
        expect(screen.queryByText('26–50 of 137')).not.toBeInTheDocument();
        expect(screen.queryByText('0–0 of 137')).not.toBeInTheDocument();

        rerender(<LocaleProvider><Pagination {...props} returnedCount={-1} /></LocaleProvider>);
        expect(screen.queryByText('0–0 of 137')).not.toBeInTheDocument();
        rerender(<LocaleProvider><Pagination {...props} returnedCount={Number.NaN} /></LocaleProvider>);
        expect(screen.queryByText('26–50 of 137')).not.toBeInTheDocument();

        rerender(<LocaleProvider><Pagination {...props} returnedCount={0} /></LocaleProvider>);
        expect(screen.getByText('0–0 of 137')).toBeInTheDocument();
        rerender(<LocaleProvider><Pagination {...props} returnedCount={3} /></LocaleProvider>);
        expect(screen.getByText('26–28 of 137')).toBeInTheDocument();
    });

    it('keeps Arabic page navigation without inventing an unknown visible range', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(<LocaleProvider><Pagination page={2} lastPage={6} onPage={vi.fn()} total={137} pageSize={25} /></LocaleProvider>);
        expect(screen.getByText('صفحة 2 من 6')).toBeInTheDocument();
        expect(screen.queryByText('26–50 من 137')).not.toBeInTheDocument();
        expect(screen.queryByText('0–0 من 137')).not.toBeInTheDocument();
    });

    it('does not invent Content Explorer ranges for missing or invalid page-local counts', () => {
        const onPage = vi.fn();
        const props = { page: 3, lastPage: 7, onPage, total: 63, pageSize: 10 };
        const { rerender } = render(<LocaleProvider><ContentExplorerPagination {...props} /></LocaleProvider>);
        expect(screen.getByText('Page 3 of 7')).toBeInTheDocument();
        expect(screen.queryByText('21–30 of 63')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Page 4' }));
        expect(onPage).toHaveBeenCalledWith(4);

        for (const badCount of [-1, Number.NaN, Number.POSITIVE_INFINITY, 1.5, Number.MAX_SAFE_INTEGER + 1]) {
            rerender(<LocaleProvider><ContentExplorerPagination {...props} returnedCount={badCount} /></LocaleProvider>);
            expect(screen.queryByText('21–30 of 63')).not.toBeInTheDocument();
            expect(screen.queryByText('0–0 of 63')).not.toBeInTheDocument();
        }

        rerender(<LocaleProvider><ContentExplorerPagination {...props} returnedCount={0} /></LocaleProvider>);
        expect(screen.getByText('0–0 of 63')).toBeInTheDocument();
        rerender(<LocaleProvider><ContentExplorerPagination {...props} returnedCount={3} /></LocaleProvider>);
        expect(screen.getByText('21–23 of 63')).toBeInTheDocument();
        rerender(<LocaleProvider><ContentExplorerPagination {...props} returnedCount={999} /></LocaleProvider>);
        expect(screen.getByText('21–30 of 63')).toBeInTheDocument();
    });

    it('keeps Arabic Content Explorer navigation when the visible count is unknown', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        const onPage = vi.fn();
        render(<LocaleProvider><ContentExplorerPagination page={3} lastPage={7} onPage={onPage} total={63} pageSize={10} /></LocaleProvider>);
        expect(screen.getByText('صفحة 3 من 7')).toBeInTheDocument();
        expect(screen.queryByText('21–30 من 63')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'الأولى' }));
        expect(onPage).toHaveBeenCalledWith(1);
    });

    it('renders Arabic RTL labels, numbers and safe exploration navigation', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        const onPage = vi.fn();
        render(<LocaleProvider><ContentExplorerPagination page={3} lastPage={7} onPage={onPage} total={63}
            pageSize={10} returnedCount={10} onPageSizeChange={vi.fn()} /></LocaleProvider>);
        expect(screen.getByRole('navigation', { name: 'ترقيم صفحات المحتوى' })).toBeInTheDocument();
        expect(screen.getByText('21–30 من 63')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'الصفحة 3' })).toHaveAttribute('aria-current', 'page');
        fireEvent.click(screen.getByRole('button', { name: 'الأولى' }));
        fireEvent.click(screen.getByRole('button', { name: 'الأخيرة' }));
        expect(onPage.mock.calls.map(call => call[0])).toEqual([1, 7]);
    });
});
