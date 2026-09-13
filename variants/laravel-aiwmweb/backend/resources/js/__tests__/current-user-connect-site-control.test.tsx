import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    CURRENT_USER_CONNECT_SITE_OPERATION_ID,
    CurrentUserConnectSiteControl,
} from '../current-user-connect-site-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type ContextWithActiveSite = FrontendContext & {
    active_site?: { id: number; name: string; status?: string | null } | null;
};

function context(overrides: Partial<ContextWithActiveSite> = {}): ContextWithActiveSite {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.manage'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
        active_site: null,
        ...overrides,
    };
}

function jsonResponse(payload: unknown, status = 200) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

function renderControl(value: ContextWithActiveSite) {
    const host = document.createElement('div');
    host.className = 'user-chip';
    document.body.appendChild(host);

    return render(
        <LocaleProvider>
            <CurrentUserConnectSiteControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    document.querySelectorAll('.user-chip').forEach((node) => node.remove());
    vi.unstubAllGlobals();
});

describe(`${CURRENT_USER_CONNECT_SITE_OPERATION_ID} CurrentUserChip first-site connection`, () => {
    it('shows the canonical connect destination only after the authoritative tenant sites read proves the list is empty', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse([]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context());

        const link = await screen.findByRole('link', { name: 'Connect your first site' });
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_CONNECT_SITE_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/connect');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/sites');
    });

    it('does not render when the tenant already has any site', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([{ id: 12, name: 'Existing Site' }])));

        renderControl(context());

        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect your first site' })).not.toBeInTheDocument());
    });

    it('fails closed without sites manage permission and performs no sites read', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ permissions: ['tenant.view'] }));

        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect your first site' })).not.toBeInTheDocument());
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fails closed for an active site, missing endpoint, malformed response, or read failure', async () => {
        const activeFetch = vi.fn();
        vi.stubGlobal('fetch', activeFetch);
        const active = renderControl(context({ active_site: { id: 7, name: 'Active Site' } }));
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect your first site' })).not.toBeInTheDocument());
        expect(activeFetch).not.toHaveBeenCalled();
        active.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        const missingFetch = vi.fn();
        vi.stubGlobal('fetch', missingFetch);
        const missing = renderControl(context({ api: {} }));
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect your first site' })).not.toBeInTheDocument());
        expect(missingFetch).not.toHaveBeenCalled();
        missing.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ data: [] })));
        const malformed = renderControl(context());
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect your first site' })).not.toBeInTheDocument());
        malformed.unmount();
        document.querySelectorAll('.user-chip').forEach((node) => node.remove());

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network unavailable')));
        renderControl(context());
        await waitFor(() => expect(screen.queryByRole('link', { name: 'Connect your first site' })).not.toBeInTheDocument());
    });
});
