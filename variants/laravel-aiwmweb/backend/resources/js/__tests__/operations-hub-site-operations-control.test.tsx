import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    OPERATIONS_HUB_SITE_OPERATIONS_OPERATION_ID,
    OperationsHubSiteOperationsControl,
} from '../operations-hub-site-operations-control';

function context(
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'execution.view'],
    tenantSlugs: string[] = [slug],
    siteOperationsApi = `/tenants/${encodeURIComponent(slug)}/route-api/site-operations`,
): FrontendContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug, name: 'Alpha' },
        tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
        permissions,
        connectors: [],
        capabilities: {},
        api: { 'site-operations': siteOperationsApi },
        actions: {},
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <OperationsHubSiteOperationsControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-BCAD1E3A49 Operations Hub site-operation history navigation', () => {
    it('opens the real tenant-scoped Site Operations workspace with exact operation provenance', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Site Operations/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/site-operations');
        expect(link).toHaveAttribute('data-canonical-operation', OPERATIONS_HUB_SITE_OPERATIONS_OPERATION_ID);
        expect(OPERATIONS_HUB_SITE_OPERATIONS_OPERATION_ID).toBe('AIMW-CONT-BCAD1E3A49');
    });

    it('fails closed without execution.view or an authoritative active tenant membership', () => {
        renderControl(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: /Site Operations/i })).not.toBeInTheDocument();

        cleanup();
        renderControl(context('alpha', ['tenant.view', 'execution.view'], ['beta']));
        expect(screen.queryByRole('link', { name: /Site Operations/i })).not.toBeInTheDocument();
    });

    it('rejects missing, foreign, or caller-diverted API contracts', () => {
        renderControl(context('alpha', undefined, ['alpha'], ''));
        expect(screen.queryByRole('link', { name: /Site Operations/i })).not.toBeInTheDocument();

        cleanup();
        renderControl(context('alpha', undefined, ['alpha'], '/tenants/beta/route-api/site-operations'));
        expect(screen.queryByRole('link', { name: /Site Operations/i })).not.toBeInTheDocument();

        cleanup();
        renderControl(context('alpha', undefined, ['alpha'], '/tenants/alpha/route-api/site-reliability'));
        expect(screen.queryByRole('link', { name: /Site Operations/i })).not.toBeInTheDocument();
    });

    it('URL-encodes the server-derived tenant slug without allowing path traversal', () => {
        renderControl(context('alpha/../beta'));
        const link = screen.getByRole('link', { name: /Site Operations/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/site-operations');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/site-operations');
    });
});
