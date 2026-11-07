import { describe, expect, it } from 'vitest';
import { withApprovalQueueEndpoint } from '../approvalQueue';
import { resolveCapability, workspaceRoutes, type FrontendContext } from '../core';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 7, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'ai.use'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
        ...overrides,
    };
}

describe('AIMW-AI-82F795EE67 AI Center API discovery', () => {
    const route = workspaceRoutes.find((item) => item.key === 'ai-center')!;

    it('refuses to fabricate an AI API when Laravel does not advertise it', () => {
        const resolved = withApprovalQueueEndpoint(context());
        expect(resolved.api['ai-center']).toBeUndefined();
        expect(resolveCapability(resolved, route).state).toBe('pending_integration');
        expect(resolved.actions['ai-center']).toBeUndefined();
    });

    it('uses the real server-authorized AI read endpoint without granting mutation', () => {
        const resolved = withApprovalQueueEndpoint(context({
            api: { 'ai-center': '/api/tenants/alpha/ai-center' },
        }));
        expect(resolved.api['ai-center']).toBe('/api/tenants/alpha/ai-center');
        expect(resolveCapability(resolved, route).state).toBe('enabled');
        expect(resolved.actions['ai-center']).toBeUndefined();
    });

    it('respects tenant RBAC even with an advertised read endpoint', () => {
        const resolved = withApprovalQueueEndpoint(context({
            permissions: ['tenant.view'],
            api: { 'ai-center': '/api/tenants/alpha/ai-center' },
        }));
        expect(resolveCapability(resolved, route).state).toBe('permission_denied');
    });

    it('keeps the historical approval-only read fallback tenant-encoded', () => {
        const resolved = withApprovalQueueEndpoint(context({
            tenant: { slug: 'alpha/../beta', name: 'Probe' },
        }));
        expect(resolved.api.approvals).toBe('/api/tenants/alpha%2F..%2Fbeta/approvals');
        expect(resolved.api['ai-center']).toBeUndefined();
    });
});
