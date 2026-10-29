import React from 'react';
import type { FrontendContext } from './core';
import { ModuleWorkspaceBackToHomeControl } from './module-workspace-back-to-home-control';

export const MODULE_WORKSPACE_ROUTE_OPERATION_ID = 'AIMW-CONT-80EE7CA296';

export function ModuleWorkspaceRouteFallback({
    context,
    moduleKey,
    children,
}: {
    context: FrontendContext;
    moduleKey: string;
    children?: React.ReactNode;
}) {
    const normalizedKey = moduleKey.trim().toLowerCase();

    return (
        <div
            className={`workspace-stack module-page module-${normalizedKey || 'unknown'}`}
            data-testid="module-workspace-fallback"
            data-canonical-operation={MODULE_WORKSPACE_ROUTE_OPERATION_ID}
        >
            {children ?? <ModuleWorkspaceBackToHomeControl context={context} />}
        </div>
    );
}
