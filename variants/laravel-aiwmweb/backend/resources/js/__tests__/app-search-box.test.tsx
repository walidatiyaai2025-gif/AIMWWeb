import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { APP_SEARCH_BOX_OPERATION_ID, AppSearchBox } from '../app-search-box';

afterEach(() => cleanup());

describe('AIMW-SYNC-1778E3F6B5 AppSearchBox', () => {
    it('emits controlled search values and the canonical operation marker', () => {
        const onValueChange = vi.fn();
        render(
            <AppSearchBox
                id="comments-search"
                value="needle"
                onValueChange={onValueChange}
                placeholder="Search live data…"
                ariaLabel="Search"
            />,
        );

        const input = screen.getByRole('searchbox', { name: 'Search' });
        expect(input).toHaveAttribute('id', 'comments-search');
        expect(input).toHaveAttribute('autocomplete', 'off');
        expect(input).toHaveAttribute('placeholder', 'Search live data…');
        expect(input).toHaveValue('needle');
        expect(input.closest('[data-canonical-operation]')).toHaveAttribute(
            'data-canonical-operation',
            APP_SEARCH_BOX_OPERATION_ID,
        );
        expect(APP_SEARCH_BOX_OPERATION_ID).toBe('AIMW-SYNC-1778E3F6B5');

        fireEvent.change(input, { target: { value: 'next' } });
        expect(onValueChange).toHaveBeenCalledWith('next');
    });

    it('exposes source-equivalent clear behavior only for non-blank idle values', () => {
        const onValueChange = vi.fn();
        const { rerender } = render(
            <AppSearchBox value="needle" onValueChange={onValueChange} clearAriaLabel="Clear search" />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Clear search' }));
        expect(onValueChange).toHaveBeenCalledWith('');

        rerender(<AppSearchBox value="   " onValueChange={onValueChange} clearAriaLabel="Clear search" />);
        expect(screen.queryByRole('button', { name: 'Clear search' })).not.toBeInTheDocument();

        rerender(<AppSearchBox value="needle" onValueChange={onValueChange} showClearButton={false} clearAriaLabel="Clear search" />);
        expect(screen.queryByRole('button', { name: 'Clear search' })).not.toBeInTheDocument();
    });

    it('shows polite busy status instead of the clear button and preserves disabled semantics', () => {
        const onValueChange = vi.fn();
        const { rerender } = render(
            <AppSearchBox value="needle" onValueChange={onValueChange} busy busyText="Searching" clearAriaLabel="Clear search" />,
        );

        expect(screen.getByRole('status')).toHaveTextContent('Searching');
        expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite');
        expect(screen.queryByRole('button', { name: 'Clear search' })).not.toBeInTheDocument();
        expect(screen.getByRole('searchbox').closest('.app-search-box')).toHaveAttribute('aria-busy', 'true');

        rerender(<AppSearchBox value="" onValueChange={onValueChange} disabled />);
        expect(screen.getByRole('searchbox')).toBeDisabled();
    });
});
