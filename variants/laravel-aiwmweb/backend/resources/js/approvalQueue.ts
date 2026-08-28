import type { FrontendContext } from './core';
import { tenantUrl } from './core';

export function withApprovalQueueEndpoint(context: FrontendContext): FrontendContext {
    if (context.api.approvals) return context;

    return {
        ...context,
        api: {
            ...context.api,
            approvals: `/api/tenants/${encodeURIComponent(context.tenant.slug)}/approvals`,
        },
    };
}

export function approvalExecutionCenterHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, '/module/execution');
}
