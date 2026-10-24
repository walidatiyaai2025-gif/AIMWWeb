import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EXPLORER_PREVIEW_EDITOR_LINK_OPERATION_ID,
    ContentExplorerPreviewEditorLinkControl,
    contentExplorerPreviewEditorHref,
} from '../content-explorer-preview-editor-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view', 'content.edit'],
        connectors: [],
        capabilities: {},
        api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer' },
        actions: {},
        active_site: { id: 17, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

const row = { wordpress_id: 71, type: 'post', title: 'Authoritative post' };

function renderControl(value: FrontendContext, previewRow = row) {
    return render(
        <LocaleProvider>
            <ContentExplorerPreviewEditorLinkControl context={value} row={previewRow} />
        </LocaleProvider>,
    );
}

describe('AIMW-CONT-9BA2C255AF Content Explorer preview editor link', () => {
    it('routes an editor to the tenant-scoped Laravel content editor', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Edit' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/17/content/post/71/edit');
        expect(link).toHaveClass('primary');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_PREVIEW_EDITOR_LINK_OPERATION_ID);
        expect(CONTENT_EXPLORER_PREVIEW_EDITOR_LINK_OPERATION_ID).toBe('AIMW-CONT-9BA2C255AF');
    });

    it('preserves source read-only View semantics for content viewers', () => {
        renderControl(context({ permissions: ['tenant.view', 'sites.view', 'content.view'] }));

        const link = screen.getByRole('link', { name: 'View' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/17/content/post/71/edit');
        expect(link).not.toHaveClass('primary');
    });

    it('supports pages and encodes the tenant slug in the internal route', () => {
        expect(contentExplorerPreviewEditorHref(
            context({
                tenant: { slug: 'alpha team', name: 'Alpha Team' },
                api: { explorer: '/api/v1/tenants/alpha%20team/sites/17/explorer' },
            }),
            { wordpress_id: 88, type: 'page' },
        )).toBe('/tenants/alpha%20team/sites/17/content/page/88/edit');
    });

    it('fails closed without content.view or an exact authoritative tenant/site Explorer binding', () => {
        const cases: Array<[FrontendContext, Record<string, unknown>]> = [
            [context({ permissions: ['tenant.view', 'sites.view'] }), row],
            [context({ active_site: null }), row],
            [context({ api: {} }), row],
            [context({ api: { explorer: '/api/v1/tenants/alpha/sites/99/explorer' } }), row],
            [context({ api: { explorer: '/api/v1/tenants/beta/sites/17/explorer' } }), row],
            [context({ api: { explorer: 'https://foreign.example.test/api/v1/tenants/alpha/sites/17/explorer' } }), row],
            [context({ api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer?site=99' } }), row],
            [context(), { wordpress_id: 0, type: 'post' }],
            [context(), { wordpress_id: 71, type: 'attachment' }],
        ];

        for (const [value, previewRow] of cases) {
            expect(contentExplorerPreviewEditorHref(value, previewRow)).toBeNull();
        }
    });

    it('renders no editor/view link for invalid authority', () => {
        renderControl(context({ permissions: ['tenant.view', 'sites.view'] }));
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
