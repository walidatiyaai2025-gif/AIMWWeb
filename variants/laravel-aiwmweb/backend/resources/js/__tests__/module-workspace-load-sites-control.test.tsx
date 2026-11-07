import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext, WorkspaceRoute } from '../core';
import {
    MODULE_WORKSPACE_DYNAMIC_SITE_LINKS_OPERATION_ID,
    MODULE_WORKSPACE_LOAD_SITES_OPERATION_ID,
    MODULE_WORKSPACE_MANAGE_SITES_OPERATION_ID,
    ModuleWorkspaceLoadSitesControl,
} from '../module-workspace-load-sites-control';
import { LocaleProvider } from '../i18n';

const seoRoute: WorkspaceRoute = {
    key: 'seo-audit',
    path: '/module/seo-audit',
    group: 'seo',
    icon: '◈',
    label: { en: 'SEO Audit', ar: 'تدقيق SEO' },
    description: { en: 'SEO', ar: 'SEO' },
    apiKey: 'seo-audit',
    kind: 'resource',
    permission: 'seo.view',
};

const seoFindingsRoute: WorkspaceRoute = {
    key: 'seo-findings',
    path: '/module/seo-findings',
    group: 'seo',
    icon: '!',
    label: { en: 'SEO Findings', ar: 'نتائج SEO' },
    description: { en: 'SEO findings', ar: 'نتائج SEO' },
    apiKey: undefined,
    kind: 'workspace',
    permission: 'seo.view',
};

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'seo.view'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
        ...overrides,
    };
}

function jsonResponse(payload: unknown, status = 200) {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

function renderControl(value = context(), route: WorkspaceRoute = seoRoute) {
    return render(
        <LocaleProvider>
            <ModuleWorkspaceLoadSitesControl context={value} route={route} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${MODULE_WORKSPACE_LOAD_SITES_OPERATION_ID} ModuleWorkspace LoadSitesAsync`, () => {
    it('loads real tenant sites and renders tenant-qualified SEO destinations', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse([
            { id: 7, name: 'Alpha WordPress', url: 'https://alpha.test', status: 'active' },
        ]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(screen.getByTestId('seo-site-picker-loading')).toHaveAttribute('aria-busy', 'true');
        expect(await screen.findByText('Alpha WordPress')).toBeInTheDocument();
        const seoLink = screen.getByTestId('seo-site-link');
        const explorerLink = screen.getByTestId('seo-site-explorer-link');
        expect(MODULE_WORKSPACE_DYNAMIC_SITE_LINKS_OPERATION_ID).toBe('AIMW-CONT-E4D7C73D4E');
        expect(seoLink).toHaveAttribute('href', '/tenants/alpha/sites/7/seo');
        expect(seoLink).toHaveAttribute('data-canonical-operation', MODULE_WORKSPACE_DYNAMIC_SITE_LINKS_OPERATION_ID);
        expect(explorerLink).toHaveAttribute('href', '/tenants/alpha/explorer?site=7');
        expect(explorerLink).toHaveAttribute('data-canonical-operation', MODULE_WORKSPACE_DYNAMIC_SITE_LINKS_OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites');
    });

    it('opens persisted SEO findings through the real site-bound SEO manager, without inventing a findings API', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse([
            { id: 7, name: 'Alpha WordPress', url: 'https://alpha.test', status: 'active' },
        ]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context(), seoFindingsRoute);

        expect(await screen.findByRole('heading', { name: 'SEO Findings' })).toBeInTheDocument();
        const seoLink = await screen.findByTestId('seo-site-link');
        expect(seoLink).toHaveTextContent('Open SEO findings');
        expect(seoLink).toHaveAttribute('href', '/tenants/alpha/sites/7/seo');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites');
    });

    it('rejects a malformed successful API payload instead of faking an empty site list, and recovers on retry', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({ message: 'Upstream site inventory unavailable' }))
            .mockResolvedValueOnce(jsonResponse([{ id: 7, name: 'Real WordPress' }]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(await screen.findByRole('alert')).toHaveTextContent('An error occurred while loading sites owned by this account.');
        expect(screen.queryByTestId('seo-no-sites')).not.toBeInTheDocument();
        expect(screen.queryByTestId('seo-manage-sites-link')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await screen.findByText('Real WordPress')).toBeInTheDocument();
        expect(screen.getByTestId('seo-site-link')).toHaveAttribute('href', '/tenants/alpha/sites/7/seo');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('rejects an invalid site row rather than silently dropping records or inventing site names', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
            data: [
                { id: 7, name: 'Valid WordPress' },
                { id: 8, name: '  ' },
            ],
        })));

        renderControl();

        expect(await screen.findByRole('alert')).toHaveTextContent('An error occurred while loading sites owned by this account.');
        expect(screen.queryByTestId('seo-no-sites')).not.toBeInTheDocument();
        expect(screen.queryByText('Valid WordPress')).not.toBeInTheDocument();
        expect(screen.queryByText('Site #8')).not.toBeInTheDocument();
    });

    it('treats a real empty paginated site collection as an empty state', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({ data: [] })));

        renderControl();

        expect(await screen.findByTestId('seo-no-sites')).toHaveTextContent('No sites available');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('refreshes by rereading the same authoritative site collection', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse([{ id: 7, name: 'First' }]))
            .mockResolvedValueOnce(jsonResponse([{ id: 8, name: 'Second' }]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        expect(await screen.findByText('First')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Refresh sites/ }));

        expect(await screen.findByText('Second')).toBeInTheDocument();
        expect(screen.queryByText('First')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('shows truthful empty state without sample sites', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse([])));
        renderControl();

        expect(await screen.findByText('No sites available')).toBeInTheDocument();
        const manageSites = screen.getByRole('link', { name: 'Manage sites' });
        expect(MODULE_WORKSPACE_MANAGE_SITES_OPERATION_ID).toBe('AIMW-CONT-56873F3DD6');
        expect(manageSites).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(manageSites).toHaveAttribute('data-canonical-operation', MODULE_WORKSPACE_MANAGE_SITES_OPERATION_ID);
        expect(manageSites).toHaveAttribute('data-testid', 'seo-manage-sites-link');
    });

    it('renders the Manage sites operation only for the authoritative ready-empty state', async () => {
        let resolveFetch: ((value: Response) => void) | undefined;
        vi.stubGlobal('fetch', vi.fn().mockImplementation(() => new Promise<Response>((resolve) => {
            resolveFetch = resolve;
        })));

        renderControl();
        expect(screen.queryByTestId('seo-manage-sites-link')).not.toBeInTheDocument();

        await waitFor(() => expect(resolveFetch).toBeDefined());
        resolveFetch!(jsonResponse([{ id: 7, name: 'Alpha WordPress' }]));
        expect(await screen.findByText('Alpha WordPress')).toBeInTheDocument();
        expect(screen.queryByTestId('seo-manage-sites-link')).not.toBeInTheDocument();
    });

    it('fails closed for a foreign advertised endpoint', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ api: { sites: '/api/tenants/beta/sites' } }));

        expect(await screen.findByRole('alert')).toHaveTextContent('The tenant site authority is unavailable.');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('surfaces authoritative read failure and retries without fake success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse({ message: 'down' }, 500))
            .mockResolvedValueOnce(jsonResponse([{ id: 9, name: 'Recovered' }]));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        expect(await screen.findByRole('alert')).toHaveTextContent('An error occurred while loading sites owned by this account.');

        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
        await waitFor(() => expect(screen.getByText('Recovered')).toBeInTheDocument());
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
