import { describe, expect, it } from 'vitest';
import {
    canOpenContentExplorerExecution,
    contentExplorerExecutionHref,
    CONTENT_EXPLORER_EXECUTION_LINK_OPERATION_ID,
    CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_CONTROL_TARGET,
    CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_OPERATION_KEY,
    CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_PATH,
} from '../content-explorer-execution-link-control';
import { workspaceRoutes, type FrontendContext } from '../core';

const context = (permissions: string[] = ['tenant.view', 'sites.view', 'execution.view', 'operations.manage']): FrontendContext => ({
    user: { id: 1, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha workspace', name: 'Alpha' },
    tenants: [{ slug: 'alpha workspace', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('canonical Content Explorer execution visible control', () => {
    it('binds the exact canonical source operation to the guarded execution workspace', () => {
        const explorerRoute = workspaceRoutes.find((route) => route.key === 'explorer');
        const executionRoute = workspaceRoutes.find((route) => route.key === 'execution');

        expect(CONTENT_EXPLORER_EXECUTION_LINK_OPERATION_ID).toBe('AIMW-AUTO-22BC08CEF1');
        expect(CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_OPERATION_KEY).toBe(
            'visible:src/AIWordPressManager.Web/Components/Pages/ContentExplorer.razor:/sites/{Id:guid}/explorer:/module/execution:/module/execution',
        );
        expect(CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_PATH).toBe('/sites/{Id:guid}/explorer');
        expect(CONTENT_EXPLORER_EXECUTION_LINK_SOURCE_CONTROL_TARGET).toBe('/module/execution');
        expect(explorerRoute?.path).toBe('/explorer');
        expect(explorerRoute?.permission).toBe('sites.view');
        expect(executionRoute?.path).toBe('/module/execution');
        expect(executionRoute?.permission).toBe('execution.view');
        expect(contentExplorerExecutionHref(context())).toBe('/tenants/alpha%20workspace/module/execution');
    });

    it('never emits the unqualified source target or a foreign tenant destination', () => {
        const href = contentExplorerExecutionHref(context());

        expect(href).not.toBe('/module/execution');
        expect(href).not.toContain('/tenants/beta/');
        expect(href).toContain('/tenants/alpha%20workspace/');
    });

    it('fails closed unless Explorer and Execution permissions are all present', () => {
        expect(canOpenContentExplorerExecution(context(['tenant.view', 'execution.view', 'operations.manage']))).toBe(false);
        expect(canOpenContentExplorerExecution(context(['tenant.view', 'sites.view', 'operations.manage']))).toBe(false);
        expect(canOpenContentExplorerExecution(context(['tenant.view', 'sites.view', 'execution.view']))).toBe(false);
        expect(canOpenContentExplorerExecution(context())).toBe(true);
        expect(canOpenContentExplorerExecution(context(['*']))).toBe(true);
    });
});
