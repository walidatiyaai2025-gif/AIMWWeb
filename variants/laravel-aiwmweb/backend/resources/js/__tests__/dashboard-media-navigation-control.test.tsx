import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import {
    DASHBOARD_MEDIA_NAVIGATION_OPERATION_ID,
    DashboardMediaNavigationControl,
} from '../dashboard-media-navigation-control';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'content.view'],
    tenantSlugs: string[] = [slug],
    capabilities: FrontendContext['capabilities'] = {},
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
    permissions,
    connectors: [],
    capabilities,
    api: {},
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <DashboardMediaNavigationControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-MEDI-799B36D113 dashboard Quick Access Media link', () => {
    it('renders the exact tenant-scoped Media destination without a mutation', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Media' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/media');
        expect(link).toHaveAttribute('data-source-control', 'dashboard-quick-access-media');
        expect(link).toHaveAttribute('data-canonical-operation', DASHBOARD_MEDIA_NAVIGATION_OPERATION_ID);
        expect(DASHBOARD_MEDIA_NAVIGATION_OPERATION_ID).toBe('AIMW-MEDI-799B36D113');
        expect(link).not.toHaveAttribute('data-method');
    });

    it('fails closed when content.view is absent', () => {
        renderControl(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: 'Media' })).not.toBeInTheDocument();
    });

    it('fails closed when the active tenant is absent from server-provided memberships', () => {
        renderControl(context('alpha', undefined, ['beta']));
        expect(screen.queryByRole('link', { name: 'Media' })).not.toBeInTheDocument();
    });

    it('honors an explicit server capability denial', () => {
        renderControl(context('alpha', undefined, ['alpha'], {
            media: { state: 'disabled_by_owner', reason: 'disabled_by_owner' },
        }));
        expect(screen.queryByRole('link', { name: 'Media' })).not.toBeInTheDocument();
    });

    it('URL-encodes only the authoritative server tenant slug', () => {
        renderControl(context('alpha/../beta'));
        const link = screen.getByRole('link', { name: 'Media' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/module/media');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/module/media');
    });
});
