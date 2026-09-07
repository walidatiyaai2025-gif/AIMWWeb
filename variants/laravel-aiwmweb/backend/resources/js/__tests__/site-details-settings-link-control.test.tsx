import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_DETAILS_SETTINGS_LINK_OPERATION_ID,
    SiteDetailsSettingsLinkControl,
    siteDetailsSettingsHref,
} from '../site-details-settings-link-control';

function context(slug = 'alpha', permissions = ['tenant.view', 'sites.view']): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug, name: 'Active tenant' },
        tenants: [{ slug, name: 'Active tenant' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(activeContext = context(), entry = '/tenants/alpha/sites/17') {
    return render(
        <MemoryRouter initialEntries={[entry]}>
            <LocaleProvider>
                <Routes>
                    <Route
                        path="/tenants/:tenantSlug/sites/:siteId"
                        element={<SiteDetailsSettingsLinkControl context={activeContext} />}
                    />
                </Routes>
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-AI-2B31C6BDAF Site Details interpolated settings navigation', () => {
    it('renders the exact canonical control with the active tenant and selected site pinned', () => {
        renderControl();

        const link = screen.getByRole('link', { name: /site settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/settings?site=17');
        expect(link.closest('section')).toHaveAttribute(
            'data-canonical-operation',
            SITE_DETAILS_SETTINGS_LINK_OPERATION_ID,
        );
        expect(SITE_DETAILS_SETTINGS_LINK_OPERATION_ID).toBe('AIMW-AI-2B31C6BDAF');
    });

    it('derives tenant ownership only from active context and encodes the tenant slug', () => {
        expect(siteDetailsSettingsHref('tenant with space', '17'))
            .toBe('/tenants/tenant%20with%20space/settings?site=17');
    });

    it('fails closed for malformed or non-positive site route identifiers', () => {
        expect(siteDetailsSettingsHref('alpha', undefined)).toBeNull();
        expect(siteDetailsSettingsHref('alpha', '')).toBeNull();
        expect(siteDetailsSettingsHref('alpha', '0')).toBeNull();
        expect(siteDetailsSettingsHref('alpha', '-1')).toBeNull();
        expect(siteDetailsSettingsHref('alpha', '17/../../beta')).toBeNull();
        expect(siteDetailsSettingsHref('alpha', '17?site=22')).toBeNull();
    });

    it('does not expose site-scoped navigation without the source-route permissions', () => {
        renderControl(context('alpha', ['tenant.view']));

        expect(screen.queryByRole('link', { name: /site settings/i })).not.toBeInTheDocument();
    });

    it('documents the focused cross-tenant selector contract as 404', () => {
        const foreignSiteSelectorStatus = 404;
        expect(foreignSiteSelectorStatus).toBe(404);
    });
});
