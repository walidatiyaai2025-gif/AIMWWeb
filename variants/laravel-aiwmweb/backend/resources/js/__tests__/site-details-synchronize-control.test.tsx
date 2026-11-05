import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DETAILS_SYNCHRONIZE_OPERATION_ID,
    SiteDetailsSynchronizeControl,
    canonicalSiteDetailsSyncEndpoints,
    siteDetailsSyncOutcome,
} from '../site-details-synchronize-control';

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { 'sites.detail.17': '/api/tenants/alpha/sites/17' },
        actions: {},
    };
}

function renderControl(activeContext = context()) {
    return render(
        <LocaleProvider>
            <SiteDetailsSynchronizeControl context={activeContext} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-94755B17E4 Site Details SynchronizeAsync', () => {
    it('starts real sync with idempotency, polls the accepted run, and rereads authoritative Site Details', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, status: 'queued' }), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, status: 'succeeded', processed: 7 }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, name: 'Alpha Site', connection_status: 'verified' }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '11111111-1111-4111-8111-111111111111' });

        renderControl();

        const button = screen.getByRole('button', { name: 'Sync now' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_DETAILS_SYNCHRONIZE_OPERATION_ID);
        expect(SITE_DETAILS_SYNCHRONIZE_OPERATION_ID).toBe('AIMW-BILL-94755B17E4');

        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3), { timeout: 2000 });
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites/17/sync');
        expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ method: 'POST' });
        const requestHeaders = fetchMock.mock.calls[0]?.[1]?.headers;
        expect(requestHeaders).toBeInstanceOf(Headers);
        expect((requestHeaders as Headers).get('Idempotency-Key')).toBe('11111111-1111-4111-8111-111111111111');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sync-runs/41');
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/17');
        expect(await screen.findByRole('status')).toHaveTextContent('authoritative Site Details');
    });

    it('shows the real redacted WordPress sync failure with its run ID', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, status: 'queued' }), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                id: 41, status: 'failed', diagnostic_code: 'WORDPRESS_SYNC_FAILED',
                failure: 'RuntimeException: Connector is revoked.',
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, name: 'Alpha Site' }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '11111111-1111-4111-8111-111111111111' });

        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Sync now' }));

        const error = await screen.findByRole('alert');
        expect(error).toHaveTextContent('RuntimeException: Connector is revoked.');
        expect(error).toHaveTextContent('WORDPRESS_SYNC_FAILED');
        expect(error).toHaveTextContent('HTTP 409');
        expect(error).toHaveTextContent('Sync Run: 41');
        expect(screen.queryByText('Synchronization completed and authoritative Site Details were reread from the server.')).not.toBeInTheDocument();
    });

    it('shows an HTTP 500 cause and request ID instead of generic Server Error', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                code: 'WORDPRESS_SYNC_START_FAILED',
                message: 'QueryException: Table sync_runs missing',
                exception_type: 'QueryException',
                request_id: 'req-456-trace',
            }), { status: 500, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 17, name: 'Alpha Site' }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '11111111-1111-4111-8111-111111111111' });

        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Sync now' }));

        const error = await screen.findByRole('alert');
        expect(error).toHaveTextContent('QueryException: Table sync_runs missing');
        expect(error).toHaveTextContent('HTTP 500');
        expect(error).toHaveTextContent('WORDPRESS_SYNC_START_FAILED');
        expect(error).toHaveTextContent('Request ID: req-456-trace');
    });

    it('fails closed when sites.manage or an exact single Site Details binding is absent', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const withoutPermission = context();
        withoutPermission.permissions = ['tenant.view', 'sites.view'];
        const first = renderControl(withoutPermission);
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
        first.unmount();

        const withoutBinding = context();
        withoutBinding.api = {};
        renderControl(withoutBinding);
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('derives only exact tenant-scoped sync/run endpoints and classifies terminal outcomes', () => {
        const endpoints = canonicalSiteDetailsSyncEndpoints('/api/tenants/alpha/sites/17');
        expect(endpoints?.sync).toBe('/api/tenants/alpha/sites/17/sync');
        expect(endpoints?.run(9)).toBe('/api/tenants/alpha/sync-runs/9');
        expect(canonicalSiteDetailsSyncEndpoints('/api/tenants/alpha/sites/../17')).toBeNull();
        expect(canonicalSiteDetailsSyncEndpoints('https://foreign.example/api/tenants/alpha/sites/17')).toBeNull();
        expect(siteDetailsSyncOutcome('queued')).toBe('pending');
        expect(siteDetailsSyncOutcome('succeeded')).toBe('success');
        expect(siteDetailsSyncOutcome('failed')).toBe('failure');
    });
});
