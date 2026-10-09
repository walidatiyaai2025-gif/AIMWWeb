import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext, WorkspaceRoute } from '../core';
import {
    MODULE_WORKSPACE_LOAD_SITES_OPERATION_ID,
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

function renderControl(value = context()) {
    return render(
        <LocaleProvider>
            <ModuleWorkspaceLoadSitesControl context={value} route={seoRoute} />
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
        expect(screen.getByTestId('seo-site-link')).toHaveAttribute('href', '/tenants/alpha/sites/7/seo');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites');
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
        expect(screen.getByRole('link', { name: 'Manage sites' })).toHaveAttribute('href', '/tenants/alpha/sites');
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
