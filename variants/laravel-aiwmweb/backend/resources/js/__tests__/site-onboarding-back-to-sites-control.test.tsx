import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_ONBOARDING_BACK_TO_SITES_OPERATION_ID,
    SiteOnboardingSaveTestSyncControl,
    siteOnboardingBackToSitesHref,
} from '../site-onboarding-save-test-sync-control';

function context(permissions: string[] = ['tenant.view', 'sites.manage']): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

function renderControl(active = context()) {
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <SiteOnboardingSaveTestSyncControl context={active} />
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-CONT-6EC0601E18 SiteOnboarding back-to-sites navigation', () => {
    it('renders the canonical tenant-qualified Back to sites control without a server mutation', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const link = screen.getByRole('link', { name: /Back to sites/ });
        expect(SITE_ONBOARDING_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-CONT-6EC0601E18');
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link).toHaveAttribute('data-canonical-operation', SITE_ONBOARDING_BACK_TO_SITES_OPERATION_ID);
        expect(link).toHaveAttribute('data-source-control', 'Back to sites');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('fails closed with the owning onboarding surface when sites.manage is absent', () => {
        renderControl(context(['tenant.view']));
        expect(screen.queryByRole('link', { name: /Back to sites/ })).not.toBeInTheDocument();
    });

    it('rejects a tenant slug that could escape the active tenant path', () => {
        expect(siteOnboardingBackToSitesHref('alpha')).toBe('/tenants/alpha/sites');
        expect(siteOnboardingBackToSitesHref('alpha/beta')).toBeNull();
        expect(siteOnboardingBackToSitesHref('')).toBeNull();
    });
});
