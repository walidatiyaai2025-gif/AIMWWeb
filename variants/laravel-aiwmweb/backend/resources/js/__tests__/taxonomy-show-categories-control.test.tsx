import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-TAXO-D5A5B530C7';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] }],
        capabilities: {},
        api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy' },
        actions: {},
    };
}

const route: WorkspaceRoute = {
    key: 'taxonomy',
    path: '/module/taxonomy',
    group: 'content',
    icon: '#',
    label: { en: 'Categories & Tags', ar: 'التصنيفات والوسوم' },
    description: { en: 'Manage content taxonomy.', ar: 'إدارة تصنيفات ووسوم المحتوى.' },
    apiKey: 'taxonomy',
    permission: 'content.view',
    connectorScope: 'taxonomy.read',
    controls: [],
    kind: 'resource',
};

function renderWorkspace() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={context()} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe(`${OPERATION_ID} Taxonomy ShowCategories`, () => {
    it('switches the authoritative taxonomy read from post_tag back to category using GET only', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 1, taxonomy: 'category', name: 'News', slug: 'news', usage_count: 2 }],
                total: 1,
                current_page: 1,
                last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 2, taxonomy: 'post_tag', name: 'Editorial', slug: 'editorial', usage_count: 3 }],
                total: 1,
                current_page: 1,
                last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ id: 3, taxonomy: 'category', name: 'Guides', slug: 'guides', usage_count: 4 }],
                total: 1,
                current_page: 1,
                last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('News')).toBeInTheDocument();
        expect(String(fetchMock.mock.calls[0][0])).toContain('/api/v1/tenants/alpha/sites/17/taxonomy?page=1&taxonomy=category');

        const categories = screen.getByRole('button', { name: 'Categories' });
        expect(categories).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(categories).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(screen.getByRole('button', { name: 'Tags' }));
        expect(await screen.findByText('Editorial')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(String(fetchMock.mock.calls[1][0])).toContain('/api/v1/tenants/alpha/sites/17/taxonomy?page=1&taxonomy=post_tag');

        const categoriesAfterTags = screen.getByRole('button', { name: 'Categories' });
        expect(categoriesAfterTags).toHaveAttribute('aria-pressed', 'false');
        fireEvent.click(categoriesAfterTags);

        expect(await screen.findByText('Guides')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        expect(String(fetchMock.mock.calls[2][0])).toContain('/api/v1/tenants/alpha/sites/17/taxonomy?page=1&taxonomy=category');
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
        expect(screen.getByRole('button', { name: 'Categories' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Tags' })).toHaveAttribute('aria-pressed', 'false');
    });
});
