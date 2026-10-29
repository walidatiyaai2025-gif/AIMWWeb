import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    DEFAULT_GLOBAL_POSTS_FILTERS,
    GLOBAL_POSTS_APPLY_FILTERS_OPERATION_ID,
    globalPostsFilterUrl,
    GlobalPostsApplyFiltersControl,
    type GlobalPostsApplyFilterState,
} from '../global-posts-apply-filters-control';
import { LocaleProvider } from '../i18n';

function renderFilters(props: {
    value?: GlobalPostsApplyFilterState;
    busy?: boolean;
    siteSelectorAvailable?: boolean;
    onChange?: (value: GlobalPostsApplyFilterState) => void;
    onApply?: () => void;
} = {}) {
    const onChange = props.onChange ?? vi.fn();
    const onApply = props.onApply ?? vi.fn();
    return {
        onChange,
        onApply,
        ...render(
            <LocaleProvider>
                <GlobalPostsApplyFiltersControl
                    value={props.value ?? DEFAULT_GLOBAL_POSTS_FILTERS}
                    sites={[{ id: 7, name: 'Alpha Site' }]}
                    busy={props.busy ?? false}
                    siteSelectorAvailable={props.siteSelectorAvailable ?? true}
                    onChange={onChange}
                    onApply={onApply}
                />
            </LocaleProvider>,
        ),
    };
}

describe('AIMW-SYNC-7A726BAF5F GlobalPostsExplorer ApplyAsync', () => {
    it('builds only the trusted tenant-scoped page read with server-side filters', () => {
        expect(GLOBAL_POSTS_APPLY_FILTERS_OPERATION_ID).toBe('AIMW-SYNC-7A726BAF5F');
        const url = globalPostsFilterUrl('alpha', {
            search: '  needle page  ',
            siteId: '7',
            status: 'publish',
            perPage: 25,
        });
        expect(url).not.toBeNull();
        const parsed = new URL(url as string, 'http://localhost');
        expect(parsed.pathname).toBe('/api/v1/tenants/alpha/global-posts');
        expect(Object.fromEntries(parsed.searchParams)).toEqual({
            page: '1', per_page: '25', search: 'needle page', status: 'publish', site_id: '7',
        });
        expect(globalPostsFilterUrl('alpha/beta', DEFAULT_GLOBAL_POSTS_FILTERS)).toBeNull();
        expect(globalPostsFilterUrl('alpha', { ...DEFAULT_GLOBAL_POSTS_FILTERS, siteId: '-1' })).toBeNull();
        expect(globalPostsFilterUrl('alpha', { ...DEFAULT_GLOBAL_POSTS_FILTERS, siteId: '1/2' })).toBeNull();
        expect(globalPostsFilterUrl('alpha', { ...DEFAULT_GLOBAL_POSTS_FILTERS, siteId: '1e2' })).toBeNull();
        expect(globalPostsFilterUrl('alpha', { ...DEFAULT_GLOBAL_POSTS_FILTERS, siteId: '+7' })).toBeNull();
        expect(globalPostsFilterUrl('alpha', { ...DEFAULT_GLOBAL_POSTS_FILTERS, siteId: ' 7' })).toBeNull();
        expect(globalPostsFilterUrl('alpha', { ...DEFAULT_GLOBAL_POSTS_FILTERS, perPage: 1000 })).toBeNull();
    });

    it('exposes source search/site/status/page-size and dispatches Apply exactly once', () => {
        const handlers = renderFilters();
        const apply = screen.getByRole('button', { name: 'Apply filters' });
        expect(apply).toHaveAttribute('data-canonical-operation', GLOBAL_POSTS_APPLY_FILTERS_OPERATION_ID);
        fireEvent.change(screen.getByRole('textbox', { name: 'Search posts' }), { target: { value: 'needle' } });
        expect(handlers.onChange).toHaveBeenCalledWith({ ...DEFAULT_GLOBAL_POSTS_FILTERS, search: 'needle' });
        fireEvent.change(screen.getByRole('combobox', { name: 'Post site' }), { target: { value: '7' } });
        expect(handlers.onChange).toHaveBeenCalledWith({ ...DEFAULT_GLOBAL_POSTS_FILTERS, siteId: '7' });
        fireEvent.change(screen.getByRole('combobox', { name: 'Post status' }), { target: { value: 'draft' } });
        expect(handlers.onChange).toHaveBeenCalledWith({ ...DEFAULT_GLOBAL_POSTS_FILTERS, status: 'draft' });
        fireEvent.change(screen.getByRole('combobox', { name: 'Page size' }), { target: { value: '25' } });
        expect(handlers.onChange).toHaveBeenCalledWith({ ...DEFAULT_GLOBAL_POSTS_FILTERS, perPage: 25 });
        fireEvent.click(apply);
        expect(handlers.onApply).toHaveBeenCalledTimes(1);
    });

    it('prevents duplicate apply while authoritative read is pending', () => {
        const handlers = renderFilters({ busy: true, siteSelectorAvailable: false });
        expect(screen.getByRole('button', { name: 'Applying filters…' })).toBeDisabled();
        expect(screen.getByRole('combobox', { name: 'Post site' })).toBeDisabled();
        expect(handlers.onApply).not.toHaveBeenCalled();
    });
});
