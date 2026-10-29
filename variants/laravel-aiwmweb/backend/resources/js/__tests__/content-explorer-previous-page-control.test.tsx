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

    it('does not render pagination when the authoritative result has a single page', () => {
        renderPagination(1, 1);
        expect(screen.queryByRole('navigation', { name: 'Content pagination' })).not.toBeInTheDocument();
    });
});
