import { describe, expect, it } from 'vitest';
import { withApprovalQueueEndpoint } from '../approvalQueue';
import type { FrontendContext } from '../core';

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

describe('AIMW-AI-82F795EE67 AI Center route contract', () => {
    it('publishes a tenant-qualified read endpoint without creating a mutation contract', () => {
        const resolved = withApprovalQueueEndpoint(context());

        expect(resolved.api['ai-center']).toBe('/api/tenants/alpha/ai-center');
        expect(resolved.actions['ai-center']).toBeUndefined();
    });

    it('encodes the active tenant and cannot synthesize a foreign tenant path', () => {
        const resolved = withApprovalQueueEndpoint(context({ tenant: { slug: 'alpha/../beta', name: 'Probe' } }));

        expect(resolved.api['ai-center']).toBe('/api/tenants/alpha%2F..%2Fbeta/ai-center');
    });

    it('preserves a server-advertised authoritative AI Center endpoint', () => {
        const resolved = withApprovalQueueEndpoint(context({ api: { 'ai-center': '/custom/authoritative/ai-center' } }));

        expect(resolved.api['ai-center']).toBe('/custom/authoritative/ai-center');
    });
});
