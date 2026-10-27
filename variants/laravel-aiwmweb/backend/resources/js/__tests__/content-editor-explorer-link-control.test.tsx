import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EDITOR_EXPLORER_LINK_OPERATION_ID,
    ContentEditorExplorerLinkControl,
    authoritativeContentEditorExplorerHref,
} from '../content-editor-save-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = {
    user: { id: 1, name: 'Editor', email: 'editor@example.test' },
    tenant: { slug: 'alpha team', name: 'Alpha Team' },
    tenants: [{ slug: 'alpha team', name: 'Alpha Team' }],
    permissions: ['tenant.view', 'sites.view', 'content.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
    active_site: { id: 7, name: 'Alpha Site', status: 'active' },
} as FrontendContext & { active_site: { id: number; name: string; status: string } };

const endpoint = '/api/v1/tenants/alpha%20team/sites/7/content/post/101/editor';

const renderControl = (
    value: FrontendContext = context,
    siteId: string | number = 7,
    editorEndpoint: string | null = endpoint,
) => render(
    <LocaleProvider>
        <ContentEditorExplorerLinkControl
            context={value}
            siteId={siteId}
            editorEndpoint={editorEndpoint}
        />
    </LocaleProvider>,
);

describe('AIMW-CONT-607ACDF4D8 Content Editor Explorer link', () => {
    it('binds the canonical operation to the authoritative tenant/site explorer target', () => {
        renderControl();

        const link = screen.getByRole('link', { name: '← Back to Explorer' });
        expect(CONTENT_EDITOR_EXPLORER_LINK_OPERATION_ID).toBe('AIMW-CONT-607ACDF4D8');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_EXPLORER_LINK_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha%20team/explorer?site=7');
    });

    it('fails closed when route site and authoritative active site disagree', () => {
        renderControl(context, 8);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(authoritativeContentEditorExplorerHref(context, 8, endpoint)).toBeNull();
    });

    it('fails closed when the editor endpoint is cross-tenant, cross-site, external, or malformed', () => {
        for (const candidate of [
            '/api/v1/tenants/beta/sites/7/content/post/101/editor',
            '/api/v1/tenants/alpha%20team/sites/8/content/post/101/editor',
            'https://attacker.example/api/v1/tenants/alpha%20team/sites/7/content/post/101/editor',
            '/api/v1/tenants/alpha%20team/sites/7/content/post/0/editor',
            '/api/v1/tenants/alpha%20team/sites/7/content/article/101/editor',
            '/api/v1/tenants/alpha%20team/sites/7/content/post/101/editor?site=8',
        ]) {
            expect(authoritativeContentEditorExplorerHref(context, 7, candidate)).toBeNull();
        }
    });

    it('requires tenant and explorer permissions and fails closed when Explorer is explicitly disabled', () => {
        const noSites = { ...context, permissions: ['tenant.view', 'content.view'] } as FrontendContext;
        const noTenant = { ...context, permissions: ['sites.view', 'content.view'] } as FrontendContext;
        const disabled = {
            ...context,
            capabilities: { explorer: { state: 'disabled_by_owner', reason: 'disabled' } },
        } as FrontendContext;

        expect(authoritativeContentEditorExplorerHref(noSites, 7, endpoint)).toBeNull();
        expect(authoritativeContentEditorExplorerHref(noTenant, 7, endpoint)).toBeNull();
        expect(authoritativeContentEditorExplorerHref(disabled, 7, endpoint)).toBeNull();
    });
});
