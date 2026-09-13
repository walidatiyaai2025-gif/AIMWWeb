import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import { workspaceRoutes, type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { AI_CENTER_METADATA_REFRESH_OPERATION_ID, WorkspacePage } from '../pages';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 7, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'ai.use'],
        connectors: [],
        capabilities: {},
        api: { 'ai-center': '/api/tenants/alpha/ai-center' },
        actions: {},
        ...overrides,
    };
}

function renderAiCenter(value = context()) {
    const route = workspaceRoutes.find((candidate) => candidate.key === 'ai-center');
    if (!route) throw new Error('AI Center route is missing');
    const client = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter>
                <LocaleProvider>
                    <ToastProvider>
                        <WorkspacePage context={value} route={route} />
                    </ToastProvider>
                </LocaleProvider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
}

function payload(status: string, usage: number) {
    return {
        data: [{ id: 1, key: 'content.rewrite', domain: 'content', title: 'Rewrite', version: 1, enabled: true }],
        total: 1,
        current_page: 1,
        last_page: 1,
        meta: {
            operation_id: AI_CENTER_METADATA_REFRESH_OPERATION_ID,
            locale: 'en',
            available_prompts: 1,
            recent_usage_count: usage,
            sites: [{ id: 3, name: 'Alpha Site' }, { id: 4, name: 'Beta Site' }],
            approval: { id: 17, status },
        },
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${AI_CENTER_METADATA_REFRESH_OPERATION_ID} AI Center LoadMetadataClickedAsync`, () => {
    it('renders the canonical Refresh data control and replaces the metadata snapshot only after an authoritative GET succeeds', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(payload('PENDING', 7)), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify(payload('APPROVED', 8)), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderAiCenter();

        const refresh = await screen.findByRole('button', { name: 'Refresh data' });
        expect(refresh).toHaveAttribute('data-canonical-operation', AI_CENTER_METADATA_REFRESH_OPERATION_ID);
        expect(screen.getByRole('region', { name: 'Data table' })).toBeInTheDocument();
        expect(screen.getByText('PENDING')).toBeInTheDocument();
        expect(screen.getByText('7')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/ai-center?page=1');
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[0][1]?.body).toBeUndefined();

        fireEvent.click(refresh);

        await waitFor(() => expect(screen.getByText('APPROVED')).toBeInTheDocument());
        expect(screen.getByText('8')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/ai-center?page=1');
        expect(fetchMock.mock.calls[1][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[1][1]?.body).toBeUndefined();
    });

    it('does not render the refresh surface without ai.use authority', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderAiCenter(context({ permissions: ['tenant.view'] }));

        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: 'Refresh data' })).not.toBeInTheDocument();
    });
});
