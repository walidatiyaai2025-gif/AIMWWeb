import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    CONTENT_EXPLORER_BACK_TO_SITES_OPERATION_ID,
    ContentExplorerBackToSitesControl,
} from '../content-explorer-back-to-sites-control';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

afterEach(() => {
    cleanup();
    window.localStorage.clear();
});

describe('AIMW-CONT-966F49CDAE Content Explorer back to Sites', () => {
    it('renders the exact source-equivalent navigation from authoritative tenant context', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <ContentExplorerBackToSitesControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /Back to sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link.closest('nav')).toHaveAttribute(
            'data-canonical-operation',
            CONTENT_EXPLORER_BACK_TO_SITES_OPERATION_ID,
        );
    });

    it('encodes the tenant slug and never accepts a caller-provided destination', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <ContentExplorerBackToSitesControl context={context('alpha/../beta')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /Back to sites/i }))
            .toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/sites');
    });

    it('preserves the Arabic label on the same tenant-scoped destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <ContentExplorerBackToSitesControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /العودة إلى المواقع/ }))
            .toHaveAttribute('href', '/tenants/alpha/sites');
    });
});
