import type { ActionContract, FrontendContext } from './core';

export type DiscoveredActionContract = Omit<ActionContract, 'method' | 'fields'> & {
    operation_id: string;
    canonical_kind: 'visible_control' | 'service' | 'api' | string;
    tenant_id: number;
    tenant_slug: string;
    site_id?: number | null;
    permission?: string | null;
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    availability: { state: string; reason?: string | null };
    fixed?: Record<string, string | number>;
    reconcile_api_key?: string | null;
    fields?: Array<NonNullable<ActionContract['fields']>[number] & { path?: boolean }>;
};

type BoundContext = FrontendContext & {
    tenant: FrontendContext['tenant'] & { id?: number };
    active_site?: { id: number; name: string } | null;
};

export class ActionContractError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'ActionContractError';
    }
}

export function discoveredAction(contract: ActionContract | DiscoveredActionContract): DiscoveredActionContract {
    return contract as DiscoveredActionContract;
}

export function prepareActionRequest(
    contract: ActionContract | DiscoveredActionContract,
    context: FrontendContext,
    values: Record<string, string | number>,
): { endpoint: string; method: string; body?: string; operationId: string } {
    const action = discoveredAction(contract);
    const bound = context as BoundContext;

    if (!/^AIMW-[A-Z]+-[0-9A-F]{10}$/.test(action.operation_id ?? '')) {
        throw new ActionContractError('The action has no valid canonical operation identity.');
    }
    if (!bound.tenant.id || action.tenant_id !== bound.tenant.id || action.tenant_slug !== bound.tenant.slug) {
        throw new ActionContractError('The action contract does not belong to the active tenant.');
    }
    if (action.site_id != null && action.site_id !== bound.active_site?.id) {
        throw new ActionContractError('The action contract does not belong to the active site.');
    }
    if (action.availability?.state !== 'enabled') {
        throw new ActionContractError(action.availability?.reason || 'The action is currently unavailable.');
    }
    if (action.permission
        && !bound.permissions.includes('*')
        && !bound.permissions.includes(action.permission)) {
        throw new ActionContractError(`Missing tenant permission: ${action.permission}`);
    }

    const payload: Record<string, string | number> = { ...(action.fixed ?? {}), ...values };
    let endpoint = action.endpoint;
    for (const field of action.fields ?? []) {
        if (!field.path) continue;
        const value = payload[field.key];
        if (value === undefined || value === '') {
            throw new ActionContractError(`Action path parameter '${field.key}' is required.`);
        }
        endpoint = endpoint.replaceAll(`{${field.key}}`, encodeURIComponent(String(value)));
        delete payload[field.key];
    }
    if (/\{[^}]+\}/.test(endpoint)) {
        throw new ActionContractError('The action endpoint still contains an unresolved ownership or path parameter.');
    }

    const body = ['GET', 'HEAD', 'DELETE'].includes(action.method.toUpperCase())
        ? undefined
        : JSON.stringify(payload);

    return { endpoint, method: action.method, body, operationId: action.operation_id };
}
