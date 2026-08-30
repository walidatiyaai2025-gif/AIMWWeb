import { describe, expect, it } from 'vitest';
import { approvalExecutionCenterHref, withApprovalQueueEndpoint } from '../approvalQueue';
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

describe('canonical ApprovalQueue execution-center visible control', () => {
    it('targets the active tenant canonical execution workspace', () => {
        const executionRoute = workspaceRoutes.find((route) => route.key === 'execution');
        expect(executionRoute?.path).toBe('/module/execution');
        expect(approvalExecutionCenterHref(context())).toBe('/tenants/alpha%20workspace/module/execution');
    });

    it('never emits the unqualified source path or a different tenant', () => {
        const href = approvalExecutionCenterHref(context());
        expect(href).not.toBe('/module/execution');
        expect(href).not.toContain('/tenants/beta/');
        expect(href).toContain('/tenants/alpha%20workspace/');
    });
});
