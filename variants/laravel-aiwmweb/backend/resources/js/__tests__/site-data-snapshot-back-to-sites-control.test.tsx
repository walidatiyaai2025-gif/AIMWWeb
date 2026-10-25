import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID,
    SiteDataSnapshotBackToSitesControl,
} from '../site-data-snapshot-back-to-sites-control';

function context(slug = 'alpha'): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Active tenant' },
        tenants: [{ slug, name: 'Active tenant' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

describe('canonical Site Data Snapshot back-to-sites control', () => {
    it('renders the exact canonical navigation to the active tenant Sites workspace', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SiteDataSnapshotBackToSitesControl context={context()} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /back to sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link.closest('nav')).toHaveAttribute(
            'data-canonical-operation',
            SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID,
        );
        expect(SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-CONT-CA4920CBC8');
    });

    it('derives and encodes the destination only from authoritative tenant context', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SiteDataSnapshotBackToSitesControl context={context('tenant with space')} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.getByRole('link', { name: /back to sites/i }))
            .toHaveAttribute('href', '/tenants/tenant%20with%20space/sites');
    });
});
