import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    ApiError,
    apiRequest,
    resolveCapability,
    switchTenantPath,
    workspaceRoutes,
    type FrontendContext,
    type WorkspaceRoute,
} from '../core';
import { ActionButton } from '../components';
import { LocaleProvider, useLocale } from '../i18n';

const baseContext = (): FrontendContext => ({
    user: { id: 1, name: 'Tester', email: 'tester@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }, { slug: 'beta', name: 'Beta' }],
    permissions: ['tenant.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const route = (overrides: Partial<WorkspaceRoute> = {}): WorkspaceRoute => ({
    key: 'posts',
    path: '/module/posts',
    group: 'content',
    icon: '▤',
    label: { en: 'Posts', ar: 'المقالات' },
    description: { en: 'Posts', ar: 'المقالات' },
    apiKey: 'posts',
    permission: 'content.view',
    connectorScope: 'posts.read',
    controls: ['posts.create'],
    ...overrides,
});

describe('frontend parity route map', () => {
    it('covers the broad AIMWWeb workspaces without dead hash routes', () => {
        const keys = new Set(workspaceRoutes.map((item) => item.key));
        for (const required of [
            'dashboard', 'sites', 'site-details', 'explorer', 'posts', 'pages', 'media', 'comments',
            'categories', 'tags', 'seo-audit', 'seo-findings', 'seo-suggestions', 'ai-center', 'ai-providers',
            'approvals', 'execution', 'evidence', 'schedules', 'automation', 'sync', 'backups', 'import-export',
            'application-users', 'roles', 'sessions', 'logs', 'diagnostics', 'reports', 'settings', 'workspace',
        ]) expect(keys.has(required)).toBe(true);

        expect(workspaceRoutes.length).toBeGreaterThanOrEqual(45);
        expect(workspaceRoutes.every((item) => item.path.startsWith('/') && !item.path.includes('#'))).toBe(true);
        expect(new Set(workspaceRoutes.map((item) => item.key)).size).toBe(workspaceRoutes.length);
        expect(new Set(workspaceRoutes.map((item) => item.path)).size).toBe(workspaceRoutes.length);
    });
});

describe('capability awareness', () => {
    it('blocks missing tenant permissions before any UI mutation is offered', () => {
        const state = resolveCapability(baseContext(), route());
        expect(state.state).toBe('permission_denied');
        expect(state.reason).toContain('content.view');
    });

    it('shows connector-disabled state instead of pretending the feature disappeared', () => {
        const context = baseContext();
        context.permissions.push('content.view');
        context.connectors.push({ key: 'wordpress', state: 'disconnected', scopes: ['posts.read'], reason: 'disabled_by_owner' });
        const state = resolveCapability(context, route());
        expect(state.state).toBe('disabled_by_owner');
    });

    it('uses typed pending integration when backend endpoint is not advertised', () => {
        const context = baseContext();
        context.permissions.push('content.view');
        context.connectors.push({ key: 'wordpress', state: 'connected', scopes: ['posts.read'] });
        const state = resolveCapability(context, route());
        expect(state.state).toBe('pending_integration');
        expect(state.reason).toContain('posts');
    });

    it('enables a view only after permission, connector scope, and API contract are all present', () => {
        const context = baseContext();
        context.permissions.push('content.view');
        context.connectors.push({ key: 'wordpress', state: 'connected', scopes: ['posts.read'] });
        context.api.posts = '/api/tenants/alpha/posts';
        expect(resolveCapability(context, route()).state).toBe('enabled');
    });

    it('renders unavailable action controls disabled with an explicit reason', () => {
        const context = baseContext();
        context.permissions.push('content.view');
        context.connectors.push({ key: 'wordpress', state: 'connected', scopes: ['posts.read'] });
        context.api.posts = '/api/tenants/alpha/posts';
        render(<LocaleProvider><ActionButton route={route()} actionKey="posts.create" context={context} onAvailable={() => undefined} /></LocaleProvider>);
        const button = screen.getByRole('button', { name: /create/i });
        expect(button).toBeDisabled();
        expect(screen.getByText(/Action contract 'posts.create'/)).toBeInTheDocument();
    });
});

describe('tenant and localization behavior', () => {
    it('preserves the workspace route when switching tenant', () => {
        expect(switchTenantPath('/tenants/alpha/module/posts', 'beta')).toBe('/tenants/beta/module/posts');
    });

    it('switches document direction with Arabic and restores LTR', () => {
        function Probe() {
            const { locale, toggleLocale } = useLocale();
            return <button type="button" onClick={toggleLocale}>{locale}</button>;
        }
        render(<LocaleProvider><Probe /></LocaleProvider>);
        expect(document.documentElement.dir).toBe('ltr');
        fireEvent.click(screen.getByRole('button', { name: 'en' }));
        expect(screen.getByRole('button', { name: 'ar' })).toBeInTheDocument();
        expect(document.documentElement.dir).toBe('rtl');
        fireEvent.click(screen.getByRole('button', { name: 'ar' }));
        expect(document.documentElement.dir).toBe('ltr');
    });
});

describe('API client failure semantics', () => {
    it('surfaces validation failures without fake success', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            message: 'Validation failed',
            errors: { title: ['Title is required'] },
        }), { status: 422, headers: { 'content-type': 'application/json' } })));

        await expect(apiRequest('/api/example', { method: 'POST', body: JSON.stringify({}) })).rejects.toMatchObject<ApiError>({
            status: 422,
            message: 'Validation failed',
            validation: { title: ['Title is required'] },
        });
    });

    it('does not retry or convert a forbidden response into a success payload', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Forbidden' }), {
            status: 403,
            headers: { 'content-type': 'application/json' },
        })));
        await expect(apiRequest('/api/example')).rejects.toMatchObject({ status: 403, message: 'Forbidden' });
    });
});
