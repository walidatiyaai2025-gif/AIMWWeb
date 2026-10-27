import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EXPLORER_SELECT_ALL_FILTERED_OPERATION_ID,
    canonicalContentExplorerEndpoint,
    contentExplorerSelectionKey,
    ContentExplorerSelectAllFilteredControl,
    loadAllFilteredExplorerKeys,
} from '../content-explorer-select-all-filtered-control';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';

const context = {
    tenant: { slug: 'alpha', name: 'Alpha' },
    permissions: ['tenant.view', 'sites.view', 'content.edit'],
} as unknown as FrontendContext;

describe('AIMW-CONT-AC737C80BF ContentExplorer SelectAllFiltered', () => {
    it('accepts only the authoritative active-tenant explorer endpoint', () => {
        expect(canonicalContentExplorerEndpoint(context, '/api/v1/tenants/alpha/sites/7/explorer')).toBe('/api/v1/tenants/alpha/sites/7/explorer');
        expect(canonicalContentExplorerEndpoint(context, '/api/v1/tenants/other/sites/7/explorer')).toBeNull();
        expect(canonicalContentExplorerEndpoint(context, '/api/v1/tenants/alpha/sites/7/explorer?site=8')).toBeNull();
    });

    it('uses the source-equivalent content type and WordPress id as selection identity', () => {
        expect(contentExplorerSelectionKey({ type: 'post', wordpress_id: 41, id: 9 })).toBe('post:41');
        expect(contentExplorerSelectionKey({ type: 'page', wordpress_id: 5 })).toBe('page:5');
        expect(contentExplorerSelectionKey({ wordpress_id: 5 })).toBeNull();
    });

    it('reads every authoritative filtered page and returns the complete selected key set', async () => {
        const request = vi.fn()
            .mockResolvedValueOnce({
                data: [{ type: 'post', wordpress_id: 1 }, { type: 'page', wordpress_id: 2 }],
                total: 3,
                current_page: 1,
                last_page: 2,
            })
            .mockResolvedValueOnce({
                data: [{ type: 'post', wordpress_id: 3 }],
                total: 3,
                current_page: 2,
                last_page: 2,
            });

        const keys = await loadAllFilteredExplorerKeys({
            context,
            endpoint: '/api/v1/tenants/alpha/sites/7/explorer',
            search: 'needle',
            request,
        });

        expect([...keys]).toEqual(['post:1', 'page:2', 'post:3']);
        expect(request).toHaveBeenCalledTimes(2);
        expect(request.mock.calls[0][0]).toContain('page=1');
        expect(request.mock.calls[0][0]).toContain('per_page=100');
        expect(request.mock.calls[0][0]).toContain('search=needle');
        expect(request.mock.calls[1][0]).toContain('page=2');
    });

    it('renders only for editable selected content and invokes one bounded select-all flow', async () => {
        const onSelectAll = vi.fn().mockResolvedValue(undefined);
        const view = render(
            <LocaleProvider>
                <ContentExplorerSelectAllFilteredControl canEdit selectedCount={1} total={3} onSelectAll={onSelectAll} />
            </LocaleProvider>,
        );

        const button = screen.getByRole('button', { name: 'Select all results' });
        expect(button).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_SELECT_ALL_FILTERED_OPERATION_ID);
        expect(CONTENT_EXPLORER_SELECT_ALL_FILTERED_OPERATION_ID).toBe('AIMW-CONT-AC737C80BF');

        fireEvent.click(button);
        await waitFor(() => expect(onSelectAll).toHaveBeenCalledTimes(1));

        view.unmount();
        render(
            <LocaleProvider>
                <ContentExplorerSelectAllFilteredControl canEdit={false} selectedCount={1} total={3} onSelectAll={onSelectAll} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('button', { name: 'Select all results' })).not.toBeInTheDocument();
    });
});
