import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { APP_FILTER_BAR_CLEAR_OPERATION_ID, AppFilterBar } from '../app-filter-bar';

afterEach(() => cleanup());

describe('AIMW-SYNC-F7CE702C7D shared AppFilterBar clear control', () => {
    it('renders authoritative active-filter metadata and invokes the callback-only clear control', async () => {
        const onClearRequested = vi.fn().mockResolvedValue(undefined);

        render(
            <AppFilterBar
                ariaLabel="AI usage filters"
                activeFilterCount={1}
                activeFiltersText="active filter"
                clearText="Clear filters"
                clearAriaLabel="Clear site filter"
                onClearRequested={onClearRequested}
                appliedFilters={<span>Site: Alpha Site</span>}
            >
                <label>Filter by site<select aria-label="Site filter"><option>Alpha Site</option></select></label>
            </AppFilterBar>,
        );

        const region = screen.getByRole('search', { name: 'AI usage filters' });
        expect(region).toHaveAttribute('aria-busy', 'false');
        expect(screen.getByRole('status')).toHaveTextContent('1');
        expect(screen.getByText('Site: Alpha Site')).toBeInTheDocument();

        const clear = screen.getByRole('button', { name: 'Clear site filter' });
        expect(clear).toHaveAttribute('data-canonical-operation', APP_FILTER_BAR_CLEAR_OPERATION_ID);
        expect(APP_FILTER_BAR_CLEAR_OPERATION_ID).toBe('AIMW-SYNC-F7CE702C7D');

        fireEvent.click(clear);
        await waitFor(() => expect(onClearRequested).toHaveBeenCalledTimes(1));
    });

    it('blocks clear while busy and exposes busy state on the filter region and control', () => {
        const onClearRequested = vi.fn();

        render(
            <AppFilterBar
                activeFilterCount={1}
                busy
                clearAriaLabel="Clear site filter"
                onClearRequested={onClearRequested}
            />,
        );

        expect(screen.getByRole('search')).toHaveAttribute('aria-busy', 'true');
        const clear = screen.getByRole('button', { name: 'Clear site filter' });
        expect(clear).toBeDisabled();
        expect(clear).toHaveAttribute('aria-busy', 'true');

        fireEvent.click(clear);
        expect(onClearRequested).not.toHaveBeenCalled();
    });

    it('shows the no-filter state and withholds clear/chips when no filter is active', () => {
        render(
            <AppFilterBar
                activeFilterCount={0}
                noFiltersText="Showing all sites"
                onClearRequested={vi.fn()}
                appliedFilters={<span>stale chip</span>}
            />,
        );

        expect(screen.getByText('Showing all sites')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Clear filters/i })).not.toBeInTheDocument();
        expect(screen.queryByText('stale chip')).not.toBeInTheDocument();
    });
});
