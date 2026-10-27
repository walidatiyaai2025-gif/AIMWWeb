import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    authoritativeTaxonomyBackToSitesHref,
    TAXONOMY_BACK_TO_SITES_OPERATION_ID,
    TaxonomyBackToSitesControl,
} from '../taxonomy-back-to-sites-control';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(
    overrides: Partial<FrontendContext> & { active_site?: { id: number; name?: string } | null } = {},
): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] }],
        capabilities: {},
        api: {},
        actions: {},
        ...overrides,
    } as FrontendContext;
}

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <TaxonomyBackToSitesControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-TAXO-770DAA668D Taxonomy back-to-sites control', () => {
    it('renders the exact tenant-scoped recovery navigation when no site is resolved', () => {
        renderControl(context({ active_site: null }));

        const link = screen.getByRole('link', { name: /back to sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link.closest('nav')).toHaveAttribute('data-canonical-operation', TAXONOMY_BACK_TO_SITES_OPERATION_ID);
        expect(TAXONOMY_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-TAXO-770DAA668D');
    });

    it('derives and encodes the destination only from authenticated tenant context', () => {
        expect(authoritativeTaxonomyBackToSitesHref(context({
            tenant: { slug: 'tenant with space', name: 'Encoded tenant' },
            active_site: null,
        }))).toBe('/tenants/tenant%20with%20space/sites');
    });

    it('does not render the missing-site recovery control when an active site is resolved', () => {
        renderControl(context({ active_site: { id: 17, name: 'Taxonomy Site' } }));
        expect(screen.queryByRole('link', { name: /back to sites/i })).not.toBeInTheDocument();
    });

    it('fails closed when tenant or sites navigation permission is missing', () => {
        expect(authoritativeTaxonomyBackToSitesHref(context({
            active_site: null,
            permissions: ['tenant.view', 'content.view'],
        }))).toBeNull();

        expect(authoritativeTaxonomyBackToSitesHref(context({
            active_site: null,
            permissions: ['sites.view', 'content.view'],
        }))).toBeNull();
    });
});
