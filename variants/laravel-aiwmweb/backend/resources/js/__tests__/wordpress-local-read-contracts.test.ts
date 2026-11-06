import { describe, expect, it } from 'vitest';
import { resolveCapability, workspaceRoutes, type FrontendContext } from '../core';

const keys = ['posts', 'pages', 'media', 'comments', 'taxonomy', 'categories', 'tags', 'wp-users'];

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'wordpress', name: 'WordPress' },
        tenants: [{ slug: 'wordpress', name: 'WordPress' }],
        permissions: ['tenant.view', 'content.view', 'sites.view'],
        connectors: [{ key: 'wp', state: 'connected', scopes: ['health', 'content.read'] }],
        capabilities: {},
        api: Object.fromEntries(keys.map((key) => [key, '/api/v1/tenants/wordpress/sites/1/' + key])),
        actions: {},
    };
}

describe('local WordPress read workspace routes', () => {
    it('accepts the real content.read scope for tenant-bound local read-only workspaces', () => {
        const ctx = context();
        for (const key of keys) {
            const route = workspaceRoutes.find((value) => value.key === key)!;
            expect(route.connectorScope).toBeTruthy();
            expect(resolveCapability(ctx, route).state).toBe('enabled');
        }
    });

    it('allows only advertised tenant/site local collection GETs without a paired Connector', () => {
        const ctx = context();
        ctx.connectors = [];
        for (const key of keys) {
            const route = workspaceRoutes.find((value) => value.key === key)!;
            expect(resolveCapability(ctx, route).state).toBe('enabled');
            expect(resolveCapability(ctx, route, `${key}.create`).state).toBe('connector_unavailable');
        }
    });

    it('keeps cached reads available when a remote Connector is disconnected or owner-disabled', () => {
        const ctx = context();
        ctx.connectors = [{ key: 'wp', state: 'disconnected', scopes: ['content.read'], reason: 'disabled_by_owner' }];
        const media = workspaceRoutes.find((value) => value.key === 'media')!;
        expect(resolveCapability(ctx, media).state).toBe('enabled');
        expect(resolveCapability(ctx, media, 'media.upload').state).toBe('connector_unavailable');
    });

    it('does not bypass explicit backend disabled capabilities for local reads', () => {
        const ctx = context();
        ctx.connectors = [];
        ctx.capabilities.media = { state: 'disabled_by_owner', reason: 'Media access disabled.' };
        const media = workspaceRoutes.find((value) => value.key === 'media')!;
        expect(resolveCapability(ctx, media).state).toBe('disabled_by_owner');
    });

    it('keeps the tenant permission and live API contract guards', () => {
        const ctx = context();
        const route = workspaceRoutes.find((value) => value.key === 'posts')!;
        ctx.permissions = ['sites.view'];
        expect(resolveCapability(ctx, route).state).toBe('permission_denied');
        ctx.permissions = ['content.view', 'sites.view'];
        delete ctx.api.posts;
        expect(resolveCapability(ctx, route).state).toBe('pending_integration');
    });

    it('does not turn read capability into permission for mutation', () => {
        const ctx = context();
        const route = workspaceRoutes.find((value) => value.key === 'posts')!;
        expect(resolveCapability(ctx, route).state).toBe('enabled');
        const create = resolveCapability(ctx, route, 'posts.create');
        expect(create.state).toBe('connector_unavailable');
        expect(create.reason).toContain('content.read is read-only');
        expect(create.reason).not.toContain('posts.read');
    });
});
