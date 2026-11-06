import { workspaceRoutes, type FrontendContext, type WorkspaceRoute, type CapabilityState } from './core';

export type WorkspaceCensusState =
    | 'missing_permission'
    | 'backend_restricted'
    | 'connector_read_missing'
    | 'contract_not_advertised'
    | 'declared_accessible';

export type WorkspaceCensusEntry = {
    key: string;
    path: string;
    group: WorkspaceRoute['group'];
    required_permission: string | null;
    permission_present: boolean;
    connector_read: 'not_required' | 'advertised' | 'content_read_alias' | 'not_advertised';
    view_state: WorkspaceCensusState;
    backend_capability: CapabilityState | 'not_reported';
    api_contract: 'not_required' | 'advertised' | 'not_advertised';
    write_controls_declared: number;
    write_controls_advertised: number;
};

export type WorkspaceCensus = {
    schema: 'aiwm.workspace-capability-census.v1';
    safety: { read_only: true; endpoint_probes: false; no_write_authorization_inferred: true };
    totals: {
        routes: number;
        declared_accessible: number;
        missing_permission: number;
        backend_restricted: number;
        connector_read_missing: number;
        contract_not_advertised: number;
        write_controls_declared: number;
        write_controls_advertised: number;
    };
    workspaces: WorkspaceCensusEntry[];
};

// content.read is a read-only compatibility scope from the WordPress plugin.
// It never authorizes publish, trash, bulk edit, upload or any write action.
const legacyReadScopes = new Set([
    'posts.read', 'pages.read', 'media.read', 'comments.read', 'taxonomy.read', 'users.read',
]);

export function workspaceCapabilityCensus(context: FrontendContext): WorkspaceCensus {
    const granted = new Set(context.permissions);
    const connectors = context.connectors.filter(connector => connector.state === 'connected');
    const workspaces: WorkspaceCensusEntry[] = workspaceRoutes.map(route => {
        const permissionPresent = !route.permission || granted.has('*') || granted.has(route.permission);
        const explicit = context.capabilities[`${route.key}.view`] ?? context.capabilities[route.key];
        const backend = explicit?.state ?? 'not_reported';

        let connectorRead: WorkspaceCensusEntry['connector_read'] = 'not_required';
        if (route.connectorScope) {
            if (connectors.some(connector => connector.scopes.includes(route.connectorScope!))) {
                connectorRead = 'advertised';
            } else if (legacyReadScopes.has(route.connectorScope) &&
                connectors.some(connector => connector.scopes.includes('content.read'))) {
                connectorRead = 'content_read_alias';
            } else {
                connectorRead = 'not_advertised';
            }
        }
        const apiContract = !route.apiKey ? 'not_required'
            : context.api[route.apiKey] ? 'advertised' : 'not_advertised';
        const controls = route.controls ?? [];
        const advertised = controls.filter(operation => Boolean(context.actions[operation])).length;

        let state: WorkspaceCensusState;
        if (!permissionPresent) state = 'missing_permission';
        else if (explicit && explicit.state !== 'enabled') state = 'backend_restricted';
        else if (connectorRead === 'not_advertised') state = 'connector_read_missing';
        else if (apiContract === 'not_advertised') state = 'contract_not_advertised';
        else state = 'declared_accessible';

        return {
            key: route.key,
            path: route.path,
            group: route.group,
            required_permission: route.permission ?? null,
            permission_present: permissionPresent,
            connector_read: connectorRead,
            view_state: state,
            backend_capability: backend,
            api_contract: apiContract,
            write_controls_declared: controls.length,
            write_controls_advertised: advertised,
        };
    });
    const names: WorkspaceCensusState[] = [
        'declared_accessible', 'missing_permission', 'backend_restricted',
        'connector_read_missing', 'contract_not_advertised',
    ];
    const stateCounts = Object.fromEntries(names.map(name =>
        [name, workspaces.filter(item => item.view_state === name).length])) as Record<WorkspaceCensusState, number>;

    return {
        schema: 'aiwm.workspace-capability-census.v1',
        safety: { read_only: true, endpoint_probes: false, no_write_authorization_inferred: true },
        totals: {
            routes: workspaces.length,
            ...stateCounts,
            write_controls_declared: workspaces.reduce((total, item) => total + item.write_controls_declared, 0),
            write_controls_advertised: workspaces.reduce((total, item) => total + item.write_controls_advertised, 0),
        },
        workspaces,
    };
}
