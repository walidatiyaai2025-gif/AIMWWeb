import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    needsWordPressSiteSelection, resolveCapability, workspaceRoutes,
    type FrontendContext,
} from '../core';
import { ToastProvider } from '../components';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const scopedKeys = [
    'site-details', 'site-snapshot', 'site-offline-data',
    'site-connection-center', 'site-connection-operations',
    'explorer', 'posts', 'pages', 'media', 'comments',
    'taxonomy', 'categories', 'tags', 'wp-users', 'sync', 'seo-audit',
];

const ctx = (changes: Partial<FrontendContext> = {}): FrontendContext => ({
    user: { id: 1, name: 'Tester', email: 'test@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'content.view', 'sync.view', 'seo.view'],
    connectors: [],
    capabilities: {},
    active_site: null,
    api: { sites: '/api/tenants/alpha/sites' },
    actions: {},
    ...changes,
});

const route = (key: string) => {
    const item = workspaceRoutes.find((entry) => entry.key === key);
    if (!item) throw new Error(`Missing workspace ${key}`);
    return item;
};

const renderPosts = (context: FrontendContext, locale: 'en' | 'ar' = 'en') => {
    window.localStorage.setItem('aiwm.locale', locale);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <MemoryRouter initialEntries={['/tenants/alpha/module/posts']}>
                <LocaleProvider>
                    <ToastProvider>
                        <WorkspacePage route={route('posts')} context={context} />
                    </ToastProvider>
                </LocaleProvider>
            </MemoryRouter>
        </QueryClientProvider>,
    );
};

afterEach(() => {
    cleanup();
    window.localStorage.clear();
    vi.unstubAllGlobals();
});

describe('real site-bound backend read contracts (#1519)', () => {
    it.each(scopedKeys)('explains missing site context for %s rather than claiming missing backend integration', (key) => {
        const entry = route(key);
        const context = ctx();
        expect(entry.apiKey, key).toBeDefined();
        expect(needsWordPressSiteSelection(context, entry)).toBe(true);
        const result = resolveCapability(context, entry);
        expect(result.state).toBe('pending_integration');
        expect(result.reason).toContain('Select a tenant-owned WordPress site');
        expect(result.reason).toContain(entry.apiKey);
    });

    it('preserves a genuine missing backend API warning if the server has selected the site', () => {
        const context = ctx({ active_site: { id: 7, name: 'Owned site' } });
        const entry = route('posts');
        expect(needsWordPressSiteSelection(context, entry)).toBe(false);
        expect(resolveCapability(context, entry)).toMatchObject({
            state: 'pending_integration',
            reason: "Backend API contract 'posts' has not been integrated on this Laravel head.",
        });
    });

    it('never manufactures a site read URL and does not conflate global missing contracts', () => {
        const context = ctx();
        const global = route('ai-usage');
        expect(needsWordPressSiteSelection(context, global)).toBe(false);
        expect(resolveCapability(context, global).reason).toContain('Backend API contract');
        expect(context.api.posts).toBeUndefined();
        const posted = ctx({
            api: { ...context.api, posts: '/api/v1/tenants/alpha/sites/7/content/post' },
        });
        expect(needsWordPressSiteSelection(posted, route('posts'))).toBe(false);
        expect(resolveCapability(posted, route('posts')).state).toBe('enabled');
    });

    it('still requires tenant permissions and separately advertised mutation contracts', () => {
        const context = ctx({ permissions: ['tenant.view', 'sites.view'] });
        const entry = route('posts');
        expect(resolveCapability(context, entry).state).toBe('permission_denied');

        const authorized = ctx();
        const create = resolveCapability(authorized, entry, 'posts.create');
        expect(create.state).not.toBe('enabled');
        expect(authorized.actions['posts.create']).toBeUndefined();
    });

    it('offers real tenant Sites navigation in English without any POST or remote write', () => {
        const context = ctx();
        renderPosts(context);
        expect(screen.getByRole('heading', { name: 'Select a WordPress site first' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Choose a site' }))
            .toHaveAttribute('href', '/tenants/alpha/sites');
        expect(screen.queryByText('Backend integration pending')).not.toBeInTheDocument();
        expect(context.api.posts).toBeUndefined();
    });

    it('gives a localized Arabic selection reason instead of a misleading pending-integration banner', () => {
        renderPosts(ctx(), 'ar');
        expect(screen.getByRole('heading', { name: 'اختر موقع WordPress أولًا' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'اختيار موقع من المواقع' }))
            .toHaveAttribute('href', '/tenants/alpha/sites');
    });

    it('does not offer Sites navigation to a user without sites.view', () => {
        const context = ctx({ permissions: ['tenant.view', 'content.view'] });
        renderPosts(context);
        expect(screen.getByRole('heading', { name: 'Select a WordPress site first' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Choose a site' })).not.toBeInTheDocument();
        expect(screen.getByText('You need Sites view permission to select a site.')).toBeInTheDocument();
    });
});
