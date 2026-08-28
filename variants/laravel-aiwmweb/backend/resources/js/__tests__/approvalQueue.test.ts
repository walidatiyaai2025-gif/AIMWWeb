import { describe, expect, it } from 'vitest';
import { withApprovalQueueEndpoint } from '../approvalQueue';
import { resolveCapability, workspaceRoutes, type FrontendContext } from '../core';

const context = (): FrontendContext => ({
    user: { id: 1, name: 'Reviewer', email: 'reviewer@example.test' },
    tenant: { slug: 'alpha workspace', name: 'Alpha' },
    tenants: [{ slug: 'alpha workspace', name: 'Alpha' }],
    permissions: ['tenant.view', 'approvals.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('canonical ApprovalQueue.LoadAsync frontend contract', () => {
    it('turns the existing approval workspace from pending integration into a real tenant endpoint', () => {
        const approvalRoute = workspaceRoutes.find((route) => route.key === 'approvals');
        expect(approvalRoute).toBeDefined();
        expect(resolveCapability(context(), approvalRoute!).state).toBe('pending_integration');

        const enriched = withApprovalQueueEndpoint(context());
        expect(enriched.api.approvals).toBe('/api/tenants/alpha%20workspace/approvals');
        expect(resolveCapability(enriched, approvalRoute!).state).toBe('enabled');
    });

    it('preserves a server-advertised approval endpoint instead of overriding authority', () => {
        const serverContext = context();
        serverContext.api.approvals = '/server/owned/approval-feed';

        expect(withApprovalQueueEndpoint(serverContext)).toBe(serverContext);
        expect(serverContext.api.approvals).toBe('/server/owned/approval-feed');
    });
});
