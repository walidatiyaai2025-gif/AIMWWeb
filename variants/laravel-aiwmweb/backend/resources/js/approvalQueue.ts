import type { FrontendContext } from './core';
import { tenantUrl } from './core';

export function withApprovalQueueEndpoint(context: FrontendContext): FrontendContext {
    const approvals = context.api.approvals ?? `/api/tenants/${encodeURIComponent(context.tenant.slug)}/approvals`;
    const aiCenter = context.api['ai-center'] ?? `/api/tenants/${encodeURIComponent(context.tenant.slug)}/ai-center`;

    if (context.api.approvals === approvals && context.api['ai-center'] === aiCenter) return context;

    return {
        ...context,
        api: {
            ...context.api,
            approvals,
            'ai-center': aiCenter,
        },
    };
}

export function approvalExecutionCenterHref(context: FrontendContext): string {
    return tenantUrl(context.tenant.slug, '/module/execution');
}
