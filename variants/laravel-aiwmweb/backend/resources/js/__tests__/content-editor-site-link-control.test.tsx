import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    authoritativeContentEditorSiteHref,
    CONTENT_EDITOR_SITE_LINK_OPERATION_ID,
    ContentEditorSiteLinkControl,
} from '../content-editor-save-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type ContextWithActiveSite = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

function context(overrides: Partial<ContextWithActiveSite> = {}): ContextWithActiveSite {
    return {
        user: { id: 1, name: 'Editor', email: 'editor@example.test' },
        tenant: { slug: 'alpha team', name: 'Alpha Team' },
        tenants: [{ slug: 'alpha team', name: 'Alpha Team' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: [],
        capabilities: {},
        api: { 'sites.detail.12': '/api/tenants/alpha team/sites/12' },
        actions: {},
        active_site: { id: 12, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

function renderControl(value: ContextWithActiveSite, siteId: string | number = 12) {
    render(
        <MemoryRouter>
            <LocaleProvider>
                <ContentEditorSiteLinkControl context={value} siteId={siteId} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-CONT-E89EDDF780 Content Editor site breadcrumb', () => {
    it('binds the exact canonical operation to the authoritative tenant-scoped site details route', () => {
        const value = context();

        renderControl(value);

        const link = screen.getByRole('link', { name: 'Open Alpha Site site' });
        expect(CONTENT_EDITOR_SITE_LINK_OPERATION_ID).toBe('AIMW-CONT-E89EDDF780');
        expect(authoritativeContentEditorSiteHref(value, 12)).toBe('/tenants/alpha%20team/sites/12');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_SITE_LINK_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha%20team/sites/12');
        expect(link).toHaveTextContent('Alpha Site');
    });

    it.each([
        ['requested site does not match the active site', { active_site: { id: 13, name: 'Other Site' } }, 12],
        ['authoritative site detail API binding is absent', { api: {} }, 12],
        ['tenant.view is absent', { permissions: ['sites.view', 'content.view'] }, 12],
        ['sites.view is absent', { permissions: ['tenant.view', 'content.view'] }, 12],
        ['active site name is absent', { active_site: { id: 12, name: '' } }, 12],
        ['requested site id is invalid', {}, 0],
    ])('fails closed when %s', (_label, overrides, siteId) => {
        renderControl(context(overrides as Partial<ContextWithActiveSite>), siteId);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('encodes the server-provided tenant slug instead of allowing a path escape', () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Alpha' },
            api: { 'sites.detail.12': '/api/tenants/alpha/../beta/sites/12' },
        });

        expect(authoritativeContentEditorSiteHref(value, 12)).toBe('/tenants/alpha%2F..%2Fbeta/sites/12');
        expect(authoritativeContentEditorSiteHref(value, 12)).not.toContain('/tenants/beta/');
    });
});
