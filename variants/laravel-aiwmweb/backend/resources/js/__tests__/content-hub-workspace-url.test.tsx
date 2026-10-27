import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_HUB_WORKSPACE_URL_OPERATION_ID,
    contentHubWorkspaceUrl,
    isContentHubWorkspacePath,
} from '../content-hub-workspace-url';
import { workspaceRoutes, type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions: ['tenant.view', 'content.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const contentHub = workspaceRoutes.find((route) => route.key === 'content-hub')!;

const renderHub = (entry: string) => render(
    <MemoryRouter initialEntries={[entry]}>
        <LocaleProvider>
            <WorkspacePage context={context()} route={contentHub} />
        </LocaleProvider>
    </MemoryRouter>,
);

describe('AIMW-CONT-FE1F15935E GlobalContentHub WorkspaceUrl parity', () => {
    it('preserves validated Laravel site context on the source WorkspaceUrl destinations', () => {
        renderHub('/tenants/alpha/content?site=17');

        const expected = new Map([
            ['Open synchronization', '/tenants/alpha/module/sync?site=17'],
            ['Posts', '/tenants/alpha/module/posts?site=17'],
            ['Pages', '/tenants/alpha/module/pages?site=17'],
            ['Media', '/tenants/alpha/module/media?site=17'],
            ['Categories & Tags', '/tenants/alpha/module/taxonomy?site=17'],
        ]);

        for (const [name, href] of expected) {
            const link = screen.getByRole('link', { name: new RegExp(name) });
            expect(link).toHaveAttribute('href', href);
            expect(link).toHaveAttribute('data-canonical-operation', CONTENT_HUB_WORKSPACE_URL_OPERATION_ID);
        }
    });

    it('does not widen source semantics to unrelated content workspace cards', () => {
        renderHub('/tenants/alpha/content?site=17');

        expect(screen.getByRole('link', { name: /Comments/ })).toHaveAttribute('href', '/tenants/alpha/module/comments');
        expect(screen.getByRole('link', { name: /WordPress Content Management/ })).toHaveAttribute('href', '/tenants/alpha/content-workspace');
        expect(screen.getByRole('link', { name: /Comments/ })).not.toHaveAttribute('data-canonical-operation');
    });

    it('leaves destinations unscoped without a valid site selector', () => {
        expect(contentHubWorkspaceUrl('alpha', '/module/posts', '')).toBe('/tenants/alpha/module/posts');
        expect(contentHubWorkspaceUrl('alpha', '/module/posts', '?site=../99')).toBe('/tenants/alpha/module/posts');
        expect(contentHubWorkspaceUrl('alpha', '/module/posts', '?site=0')).toBe('/tenants/alpha/module/posts');
    });

    it('recognizes exactly the source WorkspaceUrl route family', () => {
        expect(isContentHubWorkspacePath('/module/sync')).toBe(true);
        expect(isContentHubWorkspacePath('/module/posts')).toBe(true);
        expect(isContentHubWorkspacePath('/module/pages')).toBe(true);
        expect(isContentHubWorkspacePath('/module/media')).toBe(true);
        expect(isContentHubWorkspacePath('/module/taxonomy')).toBe(true);
        expect(isContentHubWorkspacePath('/module/comments')).toBe(false);
        expect(isContentHubWorkspacePath('/content-workspace')).toBe(false);
    });
});
