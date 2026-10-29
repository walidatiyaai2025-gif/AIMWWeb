import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    filterSiteOperationsRows,
    siteOperationsDateRangeIsValid,
    siteOperationsDefaultFilters,
    SiteOperationsFiltersControl,
    SITE_OPERATIONS_RESET_FILTERS_OPERATION_ID,
    type SiteOperationsFilterState,
} from '../site-operations-filters-control';

const rows = [
    {
        id: 1,
        site_id: 10,
        site: { id: 10, name: 'Alpha Site' },
        status: 'succeeded',
        operation: 'content.sync',
        message: 'Published home page',
        started_at: '2026-10-20T10:00:00Z',
        execution_job_id: 'job-alpha',
        details: { source: 'wordpress' },
    },
    {
        id: 2,
        site_id: 20,
        site: { id: 20, name: 'Beta Site' },
        status: 'failed',
        operation: 'plugin.update',
        message: 'Update failed',
        started_at: '2026-10-21T10:00:00Z',
        execution_job_id: 'job-beta',
        details: { error: 'timeout' },
    },
    {
        id: 3,
        site_id: 10,
        site: { id: 10, name: 'Alpha Site' },
        status: 'failed',
        operation: 'backup.run',
        message: 'Old failure',
        started_at: '2026-08-01T10:00:00Z',
    },
];

afterEach(() => cleanup());

describe('AIMW-CONT-915652366F Site Operations reset filters', () => {
    it('recreates the source 30-day/all-site/all-result defaults deterministically', () => {
        const defaults = siteOperationsDefaultFilters(new Date(2026, 9, 24, 12, 0, 0));

        expect(defaults).toEqual({
            siteId: '',
            status: 'all',
            fromDate: '2026-09-24',
            toDate: '2026-10-24',
        });
        expect(siteOperationsDateRangeIsValid(defaults)).toBe(true);
    });

    it('filters the authoritative retained rows by site, result, date and search text', () => {
        const filters: SiteOperationsFilterState = {
            siteId: '10',
            status: 'success',
            fromDate: '2026-10-01',
            toDate: '2026-10-24',
        };

        expect(filterSiteOperationsRows(rows, 'home', filters).map((row) => row.id)).toEqual([1]);
        expect(filterSiteOperationsRows(rows, 'job-alpha', filters).map((row) => row.id)).toEqual([1]);
        expect(filterSiteOperationsRows(rows, 'timeout', { ...filters, siteId: '20', status: 'failed' }).map((row) => row.id)).toEqual([2]);
    });

    it('fails closed for an invalid date range instead of showing misleading rows', () => {
        const filters: SiteOperationsFilterState = {
            siteId: '',
            status: 'all',
            fromDate: '2026-10-24',
            toDate: '2026-10-01',
        };

        expect(siteOperationsDateRangeIsValid(filters)).toBe(false);
        expect(filterSiteOperationsRows(rows, '', filters)).toEqual([]);
    });

    it('renders the exact canonical reset control and delegates reset without network work', () => {
        const onReset = vi.fn();
        const onChange = vi.fn();
        const value: SiteOperationsFilterState = {
            siteId: '20',
            status: 'failed',
            fromDate: '2026-10-01',
            toDate: '2026-10-24',
        };

        render(
            <SiteOperationsFiltersControl
                rows={rows}
                locale="en"
                value={value}
                onChange={onChange}
                onReset={onReset}
            />,
        );

        const button = screen.getByRole('button', { name: 'Reset filters' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_OPERATIONS_RESET_FILTERS_OPERATION_ID);
        expect(SITE_OPERATIONS_RESET_FILTERS_OPERATION_ID).toBe('AIMW-CONT-915652366F');

        fireEvent.click(button);
        expect(onReset).toHaveBeenCalledTimes(1);
    });

    it('shows honest invalid-range feedback', () => {
        render(
            <SiteOperationsFiltersControl
                rows={rows}
                locale="en"
                value={{ siteId: '', status: 'all', fromDate: '2026-10-24', toDate: '2026-10-01' }}
                onChange={() => undefined}
                onReset={() => undefined}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('The start date cannot be after the end date.');
    });
});
