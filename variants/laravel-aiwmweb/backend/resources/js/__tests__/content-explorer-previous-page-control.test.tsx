import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID,
    ContentExplorerPagination,
    previousContentExplorerPage,
} from '../content-explorer-previous-page-control';
import { LocaleProvider } from '../i18n';

function renderPagination(page: number, lastPage: number, onPage = vi.fn()) {
    return {
        onPage,
        ...render(
            <LocaleProvider>
                <ContentExplorerPagination page={page} lastPage={lastPage} onPage={onPage} />
            </LocaleProvider>,
        ),
    };
}

describe('AIMW-CONT-A31317B378 ContentExplorer PreviousPage', () => {
    it('decrements exactly one page and never produces a page below one', () => {
        expect(previousContentExplorerPage(3)).toBe(2);
        expect(previousContentExplorerPage(2)).toBe(1);
        expect(previousContentExplorerPage(1)).toBe(1);
        expect(previousContentExplorerPage(0)).toBe(1);
    });

    it('renders the canonical Previous control and requests only the immediately previous page', () => {
        const { onPage } = renderPagination(3, 5);
        const previous = screen.getByRole('button', { name: 'Previous' });

        expect(previous).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID);
        expect(CONTENT_EXPLORER_PREVIOUS_PAGE_OPERATION_ID).toBe('AIMW-CONT-A31317B378');

        fireEvent.click(previous);
        expect(onPage).toHaveBeenCalledTimes(1);
        expect(onPage).toHaveBeenCalledWith(2);
    });

    it('fails closed on the first page without issuing a page transition', () => {
        const { onPage } = renderPagination(1, 4);
        const previous = screen.getByRole('button', { name: 'Previous' });

        expect(previous).toBeDisabled();
        fireEvent.click(previous);
        expect(onPage).not.toHaveBeenCalled();
    });


    it('clamps invalid server page metadata and prevents out-of-bounds navigation', () => {
        const { onPage } = renderPagination(999, 3);
        expect(screen.getByText('Page 3 of 3')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Next' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Last' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
        expect(onPage).toHaveBeenCalledWith(2);
    });

    it('fails closed on NaN metadata and does not show imaginary rows', () => {
        const onPage = vi.fn();
        const { rerender } = render(
            <LocaleProvider><ContentExplorerPagination page={Number.NaN} lastPage={3} onPage={onPage}
                total={21} pageSize={10} returnedCount={0} /></LocaleProvider>,
        );
        expect(screen.getByText('Page 1 of 3')).toBeInTheDocument();
        expect(screen.getByText('0–0 of 21')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        expect(onPage).toHaveBeenCalledWith(2);
        rerender(<LocaleProvider><ContentExplorerPagination page={2} lastPage={3} onPage={onPage}
            total={21} pageSize={10} returnedCount={100} /></LocaleProvider>);
        expect(screen.getByText('11–20 of 21')).toBeInTheDocument();
    });

    it('does not render pagination when the authoritative result has a single page', () => {
        renderPagination(1, 1);
        expect(screen.queryByRole('navigation', { name: 'Content pagination' })).not.toBeInTheDocument();
    });
});
