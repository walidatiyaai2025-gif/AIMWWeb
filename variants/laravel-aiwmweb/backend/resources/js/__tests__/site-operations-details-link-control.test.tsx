import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    authoritativeSiteOperationDetailsHref,
    SITE_OPERATIONS_DETAILS_LINK_OPERATION_ID,
    SiteOperationsDetailsLinkControl,
} from '../site-operations-details-link-control';

const correlationId = '123e4567-e89b-42d3-a456-426614174000';

function context(
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'execution.view'],
    tenantSlugs: string[] = [slug],
): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Active tenant' },
        tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(value = context(), row: Record<string, unknown> = { correlation_id: correlationId }) {
    return render(
        <LocaleProvider>
            <SiteOperationsDetailsLinkControl context={value} row={row} />
        </LocaleProvider>,
    );
}

describe('AIMW-CONT-4147188AB1 Site Operations Details navigation', () => {
    it('uses the source-equivalent operations/sites alias with the authoritative correlation UUID', () => {
        renderControl();

        const link = screen.getByRole('link', { name: 'Details' });
        expect(link).toHaveAttribute('href', `/tenants/alpha/operations/sites/${correlationId}`);
        expect(link.tagName).toBe('A');
        expect(link).not.toHaveAttribute('target');
        expect(link).toHaveAttribute('data-canonical-operation', SITE_OPERATIONS_DETAILS_LINK_OPERATION_ID);
        expect(SITE_OPERATIONS_DETAILS_LINK_OPERATION_ID).toBe('AIMW-CONT-4147188AB1');
    });

    it('derives and encodes the tenant only from authenticated context', () => {
        expect(authoritativeSiteOperationDetailsHref(context('tenant with space'), { correlation_id: correlationId }))
            .toBe(`/tenants/tenant%20with%20space/operations/sites/${correlationId}`);
    });

    it('fails closed for missing or malformed operation correlation identifiers', () => {
        renderControl(context(), { id: 42 });
        expect(screen.queryByRole('link', { name: 'Details' })).not.toBeInTheDocument();

        expect(authoritativeSiteOperationDetailsHref(context(), { correlation_id: '../../beta' })).toBeNull();
    });

    it('fails closed without execution.view or when the active tenant is not in the server membership set', () => {
        expect(authoritativeSiteOperationDetailsHref(
            context('alpha', ['tenant.view']),
            { correlation_id: correlationId },
        )).toBeNull();

        expect(authoritativeSiteOperationDetailsHref(
            context('alpha', ['tenant.view', 'execution.view'], ['beta']),
            { correlation_id: correlationId },
        )).toBeNull();
    });
});
