import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    authoritativeSiteDataSnapshotBackToSitesHref,
    SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID,
    SiteDataSnapshotBackToSitesControl,
} from '../site-data-snapshot-back-to-sites-control';
import { type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: [],
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
                <SiteDataSnapshotBackToSitesControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-CONT-CA4920CBC8 Site Data Snapshot back-to-sites control', () => {
    it('renders the exact tenant-scoped destination with the canonical operation marker', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /back to sites/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(link.closest('nav')).toHaveAttribute('data-canonical-operation', SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID);
        expect(SITE_DATA_SNAPSHOT_BACK_TO_SITES_OPERATION_ID).toBe('AIMW-CONT-CA4920CBC8');
    });

    it('encodes the destination from authenticated tenant context only', () => {
        expect(authoritativeSiteDataSnapshotBackToSitesHref(context({
            tenant: { slug: 'tenant with space', name: 'Encoded tenant' },
        }))).toBe('/tenants/tenant%20with%20space/sites');
    });

    it('fails closed when tenant or sites navigation permission is missing', () => {
        expect(authoritativeSiteDataSnapshotBackToSitesHref(context({
            permissions: ['tenant.view', 'content.view'],
        }))).toBeNull();

        expect(authoritativeSiteDataSnapshotBackToSitesHref(context({
            permissions: ['sites.view', 'content.view'],
        }))).toBeNull();
    });
});
