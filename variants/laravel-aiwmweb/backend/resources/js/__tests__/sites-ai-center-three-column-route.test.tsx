import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Outlet, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { RouteElement } from '../app';
import { ToastProvider } from '../components';
import { workspaceRoutes, type FrontendContext, type WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';

const siteRow = { id: 11, name: 'Test Site', url: 'https://example.test', status: 'active', connection_status: 'connected' };
const response = (data: unknown) => new Response(JSON.stringify(data), {
    status: 200,
    headers: { 'content-type': 'application/json' },
});
function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'sites.manage', 'connector.manage', 'ai.use'],
        connectors: [],
        capabilities: {},
        api: {
            sites: '/api/tenants/alpha/sites',
            'ai-center': '/api/tenants/alpha/ai-center',
        },
        actions: {},
    };
}

function mountRoute(key: 'sites' | 'ai-center') {
    const route = workspaceRoutes.find((r) => r.key === key) as WorkspaceRoute | undefined;
    if (!route) throw new Error('Expected workspace route not found');
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const ctx = context();
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <MemoryRouter initialEntries={[`/tenants/alpha/${key}`]}>
                        <Routes>
                            <Route path="/tenants/:tenant" element={<Outlet context={{ context: ctx }} />}>
                                <Route path={key} element={<RouteElement route={route} />} />
                            </Route>
                        </Routes>
                    </MemoryRouter>
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.clear();
});

describe('Owner-approved 3-column page layout in actual tenant routes', () => {
    it('groups all six Sites management panels before the authoritative three-across site cards', async () => {
        const fetchMock = vi.fn().mockImplementation(() => Promise.resolve(response([siteRow])));
        vi.stubGlobal('fetch', fetchMock);
        const { container } = mountRoute('sites');

        const layout = screen.getByTestId('sites-management-grid');
        expect(layout.querySelectorAll(':scope > section.panel')).toHaveLength(6);
        expect(layout.querySelector('[aria-label="Save site"]')).not.toBeNull();
        expect(layout.querySelector('[aria-label="Retest selected sites"]')).not.toBeNull();
        expect(layout.querySelector('[aria-label="Bulk site deletion"]')).not.toBeNull();

        const cards = await screen.findByRole('list', { name: 'Site cards' });
        expect(cards.querySelectorAll('.site-record-card')).toHaveLength(1);
        expect(container.querySelector('.sites-management-grid + .workspace-stack')).not.toBeNull();
        expect(fetchMock.mock.calls.every(([, options]) => options?.method === undefined || options?.method === 'GET')).toBe(true);
    });

    it('composes AI session and history alongside the metadata and authoritatively loaded composer', async () => {
        const fetchMock = vi.fn().mockImplementation((url: string) => {
            if (url.includes('/approval-status')) return Promise.resolve(response({ data: null }));
            if (url.includes('/ai-center')) {
                return Promise.resolve(response({
                    data: [{ id: 1, key: 'content.rewrite', title: 'Rewrite', enabled: true }],
                    total: 1, current_page: 1, last_page: 1,
                    meta: { locale: 'en', available_prompts: 1, recent_usage_count: 0, sites: [], approval: null },
                }));
            }
            return Promise.resolve(response([]));
        });
        vi.stubGlobal('fetch', fetchMock);
        const { container } = mountRoute('ai-center');

        const controls = screen.getByTestId('ai-center-controls-grid');
        expect(controls.querySelector('.ai-session-controls')).not.toBeNull();
        expect(controls.querySelector('.ai-session-history')).not.toBeNull();
        await waitFor(() => expect(container.querySelector('.ai-center-resource-grid [data-ai-center-metadata]')).not.toBeNull());
        expect(container.querySelector('.ai-center-resource-grid [data-testid="ai-center-generate"]')).not.toBeNull();
        expect(container.querySelector('.ai-center-resource-grid .data-panel')).not.toBeNull();
        expect(fetchMock.mock.calls.every(([, options]) => options?.method === undefined || options?.method === 'GET')).toBe(true);
    });
});
