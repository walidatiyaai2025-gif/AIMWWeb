import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DETAILS_CANCEL_SYNCHRONIZATION_OPERATION_ID,
    SiteDetailsCancelSynchronizationControl,
    canonicalSyncCancellationEndpoints,
} from '../site-details-cancel-synchronization-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.edit'],
        connectors: [{ key: 'connector-alpha', state: 'connected', scopes: ['content.read'] }],
        capabilities: {},
        api: { sync: '/api/v1/tenants/alpha/sites/17/sync' },
        actions: {},
    };
}

function renderControl(activeContext = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SiteDetailsCancelSynchronizationControl context={activeContext} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('AIMW-AI-54BB64BB13 Site Details CancelSynchronization adaptation', () => {
    it('renders only for an active sync and requests cancellation without fabricating provider payload', async () => {
        const active = { active: true, run: { id: 91, state: 'running' } };
        const requested = {
            operation_id: SITE_DETAILS_CANCEL_SYNCHRONIZATION_OPERATION_ID,
            run: { id: 91, state: 'cancel_requested' },
        };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(active), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(requested), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValue(new Response(JSON.stringify({ active: true, run: requested.run }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const button = await screen.findByRole('button', { name: 'Cancel synchronization' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_DETAILS_CANCEL_SYNCHRONIZATION_OPERATION_ID);
        expect(button.closest('section')).toHaveAttribute('data-canonical-operation', SITE_DETAILS_CANCEL_SYNCHRONIZATION_OPERATION_ID);

        fireEvent.click(button);
        await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThanOrEqual(2));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/v1/tenants/alpha/sites/17/sync/active');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/v1/tenants/alpha/sites/17/sync/cancel');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ method: 'POST' });
        expect(fetchMock.mock.calls[1]?.[1]).not.toHaveProperty('body');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain('password');
        expect(JSON.stringify(fetchMock.mock.calls)).not.toContain('secret');
        expect(await screen.findByRole('button', { name: 'Cancellation requested…' })).toBeDisabled();
    });

    it('does not render when there is no active synchronization', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
            new Response(JSON.stringify({ active: false, run: null }), { status: 200, headers: { 'content-type': 'application/json' } }),
        ));

        renderControl();

        await waitFor(() => expect(screen.queryByRole('button', { name: 'Cancel synchronization' })).not.toBeInTheDocument());
    });

    it('fails closed before React Query hooks when content.edit authorization is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const activeContext = context();
        activeContext.permissions = ['tenant.view', 'sites.view'];

        render(
            <LocaleProvider>
                <SiteDetailsCancelSynchronizationControl context={activeContext} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: 'Cancel synchronization' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('accepts only the canonical tenant-scoped sync contract', () => {
        expect(canonicalSyncCancellationEndpoints('/api/v1/tenants/alpha/sites/17/sync')).toEqual({
            active: '/api/v1/tenants/alpha/sites/17/sync/active',
            cancel: '/api/v1/tenants/alpha/sites/17/sync/cancel',
        });
        expect(canonicalSyncCancellationEndpoints('/api/v1/tenants/alpha/sites/17/sync?site=22')).toBeNull();
        expect(canonicalSyncCancellationEndpoints('/api/v1/tenants/alpha/sites/../22/sync')).toBeNull();
        expect(canonicalSyncCancellationEndpoints('https://foreign.example/api/v1/tenants/alpha/sites/17/sync')).toBeNull();
        expect(canonicalSyncCancellationEndpoints(undefined)).toBeNull();
    });
});
