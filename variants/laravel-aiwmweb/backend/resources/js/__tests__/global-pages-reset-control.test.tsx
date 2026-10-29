import React from 'react';
import { readFileSync } from 'node:fs';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DEFAULT_GLOBAL_PAGES_FILTERS } from '../global-pages-apply-filters-control';
import {
    GLOBAL_PAGES_RESET_OPERATION_ID,
    globalPagesResetUrl,
    GlobalPagesResetControl,
} from '../global-pages-reset-control';
import { LocaleProvider } from '../i18n';

describe('AIMW-SYNC-FD27B795ED GlobalPagesExplorer ResetAsync', () => {
    it('restores all source defaults and produces a fresh page-one tenant-scoped read', () => {
        expect(GLOBAL_PAGES_RESET_OPERATION_ID).toBe('AIMW-SYNC-FD27B795ED');
        expect(DEFAULT_GLOBAL_PAGES_FILTERS).toEqual({
            search: '', siteId: '', status: 'all', perPage: 25,
        });
        expect(globalPagesResetUrl('alpha')).toBe('/api/v1/tenants/alpha/global-pages?page=1&per_page=25');
        expect(globalPagesResetUrl('alpha/beta')).toBeNull();
        expect(globalPagesResetUrl('')).toBeNull();
        const parsed = new URL(globalPagesResetUrl('alpha') as string, 'http://localhost');
        expect(parsed.searchParams.has('site_id')).toBe(false);
        expect(parsed.searchParams.has('tenant_id')).toBe(false);
        expect(parsed.searchParams.has('actor_id')).toBe(false);
    });

    it('wires the real reset action, disables duplicate clicks, and fails closed without read permission', () => {
        const onReset = vi.fn();
        const { rerender } = render(
            <LocaleProvider>
                <GlobalPagesResetControl available busy={false} onReset={onReset} />
            </LocaleProvider>,
        );
        const button = screen.getByRole('button', { name: 'Reset' });
        expect(button).toHaveAttribute('data-canonical-operation', GLOBAL_PAGES_RESET_OPERATION_ID);
        fireEvent.click(button);
        expect(onReset).toHaveBeenCalledTimes(1);

        rerender(
            <LocaleProvider>
                <GlobalPagesResetControl available busy onReset={onReset} />
            </LocaleProvider>,
        );
        expect(screen.getByRole('button', { name: 'Resetting…' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Resetting…' }));
        expect(onReset).toHaveBeenCalledTimes(1);

        rerender(
            <LocaleProvider>
                <GlobalPagesResetControl available={false} busy={false} onReset={onReset} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('button', { name: 'Reset' })).toBeNull();
    });

    it('resets draft and applied filters, clears selection and forces an authoritative reread', () => {
        const widget = readFileSync('resources/js/global-pages-confirm-trash-widget.tsx', 'utf8');
        expect(widget).toContain('GlobalPagesResetControl');
        expect(widget).toContain('setFilterDraft({ ...DEFAULT_GLOBAL_PAGES_FILTERS })');
        expect(widget).toContain('setAppliedFilters({ ...DEFAULT_GLOBAL_PAGES_FILTERS })');
        expect(widget).toContain('setSelected(new Set())');
        expect(widget).toContain('setFilterRevision((value) => value + 1)');
        expect(widget).toContain("const canView = hasPermission(context, 'content.view')");
        expect(widget).toContain('available={enabled}');
        expect(widget).toContain('busy={busy || query.isFetching}');
        expect(widget).toContain("queryKey: ['global-pages-confirm-trash', context.tenant.slug, filterUrl, filterRevision]");
    });
});
