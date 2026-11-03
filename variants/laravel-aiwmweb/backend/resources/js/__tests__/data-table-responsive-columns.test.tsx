import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { DataTable } from '../components';
import { LocaleProvider } from '../i18n';

afterEach(() => {
    cleanup();
    window.localStorage.clear();
});

describe('Responsive data-only workspace layout', () => {
    it('marks a single-field record list for three-column desktop cards without dropping rows', () => {
        const { container } = render(
            <LocaleProvider>
                <DataTable
                    rows={[{ name: 'First site' }, { name: 'Second site' }, { name: 'Third site' }]}
                    renderActions={(row) => <button type="button">Open {String(row.name)}</button>}
                />
            </LocaleProvider>,
        );

        const grid = container.querySelector('.single-column-records');
        expect(grid).not.toBeNull();
        expect(grid).toHaveAttribute('data-data-columns', '1');
        expect(grid?.querySelectorAll('tbody tr')).toHaveLength(3);
        expect(grid?.querySelector('td[data-label="name"]')).toHaveTextContent('First site');
        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Open Third site' })).toBeInTheDocument();
    });

    it('keeps multi-field tables in their canonical full-width tabular form', () => {
        const { container } = render(
            <LocaleProvider>
                <DataTable rows={[{ id: 42, name: 'Production', status: 'connected' }]} />
            </LocaleProvider>,
        );

        const scroll = container.querySelector('.table-scroll');
        expect(scroll).toHaveAttribute('data-data-columns', '3');
        expect(scroll).not.toHaveClass('single-column-records');
        expect(screen.getByRole('columnheader', { name: 'status' })).toBeInTheDocument();
    });

    it('retains Arabic labels and row content when the record layout is compact', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        const { container } = render(
            <LocaleProvider>
                <DataTable rows={[{ name: 'موقع وليد' }]} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('region', { name: 'جدول البيانات' })).toBeInTheDocument();
        expect(container.querySelector('.single-column-records td[data-label="name"]'))
            .toHaveTextContent('موقع وليد');
    });
});
