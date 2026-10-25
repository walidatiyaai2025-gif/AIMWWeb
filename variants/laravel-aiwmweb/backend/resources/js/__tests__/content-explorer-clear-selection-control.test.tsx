import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EXPLORER_CLEAR_SELECTION_OPERATION_ID,
    ContentExplorerClearSelectionControl,
} from '../content-explorer-clear-selection-control';
import { LocaleProvider } from '../i18n';

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe('AIMW-CONT-F9015FDB11 ContentExplorer ClearSelection', () => {
    it('binds the canonical operation to the real clear-selection action without network work', () => {
        const onClear = vi.fn();
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <LocaleProvider>
                <ContentExplorerClearSelectionControl
                    canEdit
                    selectedCount={3}
                    onClear={onClear}
                />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Clear selection' });
        expect(button).toHaveAttribute(
            'data-canonical-operation',
            CONTENT_EXPLORER_CLEAR_SELECTION_OPERATION_ID,
        );
        expect(CONTENT_EXPLORER_CLEAR_SELECTION_OPERATION_ID).toBe('AIMW-CONT-F9015FDB11');

        fireEvent.click(button);

        expect(onClear).toHaveBeenCalledTimes(1);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('fails closed when selection is unavailable, read-only, or busy', () => {
        const onClear = vi.fn();
        const view = render(
            <LocaleProvider>
                <ContentExplorerClearSelectionControl
                    canEdit={false}
                    selectedCount={2}
                    onClear={onClear}
                />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Clear selection' })).not.toBeInTheDocument();

        view.rerender(
            <LocaleProvider>
                <ContentExplorerClearSelectionControl
                    canEdit
                    selectedCount={0}
                    onClear={onClear}
                />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('button', { name: 'Clear selection' })).not.toBeInTheDocument();

        view.rerender(
            <LocaleProvider>
                <ContentExplorerClearSelectionControl
                    canEdit
                    selectedCount={2}
                    busy
                    onClear={onClear}
                />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Clear selection' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onClear).not.toHaveBeenCalled();
    });

    it('renders the Arabic accessible label against the same local action', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        const onClear = vi.fn();

        render(
            <LocaleProvider>
                <ContentExplorerClearSelectionControl
                    canEdit
                    selectedCount={1}
                    onClear={onClear}
                />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'مسح التحديد' });
        fireEvent.click(button);
        expect(onClear).toHaveBeenCalledTimes(1);
    });
});
