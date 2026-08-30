import { describe, expect, it } from 'vitest';
import { resolveCapability, workspaceRoutes, type FrontendContext } from '../core';

const route = workspaceRoutes.find((candidate) => candidate.key === 'ai-usage');

function context(permissions: string[], api: Record<string, string>): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api,
        actions: {},
    };
}

describe('canonical AI usage route binding', () => {
    it('keeps the exact source workspace contract', () => {
        expect(route).toBeDefined();
        expect(route?.path).toBe('/module/ai-usage');
        expect(route?.permission).toBe('ai.viewUsage');
        expect(route?.apiKey).toBe('ai-usage');
    });

    it('is enabled only when permission and the real API contract are both present', () => {
        expect(resolveCapability(
            context(['tenant.view', 'ai.viewUsage'], { 'ai-usage': '/api/v1/tenants/alpha/ai/usage' }),
            route!,
        )).toEqual({ state: 'enabled' });

        expect(resolveCapability(
            context(['tenant.view', 'ai.viewUsage'], {}),
            route!,
        ).state).toBe('pending_integration');

        expect(resolveCapability(
            context(['tenant.view'], { 'ai-usage': '/api/v1/tenants/alpha/ai/usage' }),
            route!,
        ).state).toBe('permission_denied');
    });
});
