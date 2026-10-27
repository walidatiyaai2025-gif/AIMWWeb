import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_RELIABILITY_OPERATION_HISTORY_LINK_OPERATION_ID,
    SiteReliabilityOperationHistoryLinkControl,
} from '../site-reliability-operation-history-link-control';

function context(
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'sites.view', 'execution.view'],
    tenantSlugs: string[] = [slug],
): FrontendContext {
    return {
        user: { id: 10, name: 'Alpha Operator', email: 'alpha@example.test' },
        tenant: { slug, name: 'Alpha' },
        tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
        permissions,
        connectors: [],
        capabilities: {},
        api: {
            'site-operations': `/tenants/${encodeURIComponent(slug)}/route-api/site-operations`,
        },
        actions: {},
    };
}

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <SiteReliabilityOperationHistoryLinkControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-F4CBF0D714 Site Reliability operation history navigation', () => {
    it('navigates to the authoritative tenant-scoped /site-operations workspace', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Operation history' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/site-operations');
        expect(link).toHaveAttribute(
            'data-canonical-operation',
            SITE_RELIABILITY_OPERATION_HISTORY_LINK_OPERATION_ID,
        );
        expect(SITE_RELIABILITY_OPERATION_HISTORY_LINK_OPERATION_ID).toBe('AIMW-CONT-F4CBF0D714');
        expect(link).not.toHaveAttribute('data-method');
    });

    it('fails closed when execution.view is absent', () => {
        renderControl(context('alpha', ['tenant.view', 'sites.view']));
        expect(screen.queryByRole('link', { name: 'Operation history' })).not.toBeInTheDocument();
    });

    it('fails closed when the active tenant is not in the server-provided membership set', () => {
        renderControl(context('alpha', undefined, ['beta']));
        expect(screen.queryByRole('link', { name: 'Operation history' })).not.toBeInTheDocument();
    });

    it('URL-encodes the authoritative tenant slug', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: 'Operation history' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/site-operations');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/site-operations');
    });
});
