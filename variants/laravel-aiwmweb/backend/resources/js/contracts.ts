export interface LaravelPage<T> {
    data: T[];
    current_page?: number;
    last_page?: number;
    per_page?: number;
    total?: number;
}

export interface SiteRecord {
    id: number;
    name: string;
    url: string;
    status: 'active' | 'disabled' | string;
    connection_status: string;
    health_state: string;
    last_verified_at?: string | null;
    last_sync_at?: string | null;
}

export interface ConnectorRecord {
    id: number;
    site_id: number;
    identity: string;
    protocol_version: string;
    capabilities: string[];
    enabled_scopes: string[];
    verified_at?: string | null;
    revoked_at?: string | null;
}

export interface ContentItemRecord {
    id: number;
    site_id: number;
    type: 'post' | 'page';
    remote_id: number;
    title?: string | null;
    slug?: string | null;
    status?: string | null;
    remote_modified_at?: string | null;
    remote_hash?: string | null;
    remote_version?: string | null;
}

export interface MediaRecord {
    id: number;
    site_id: number;
    remote_id: number;
    title?: string | null;
    alt_text?: string | null;
    mime_type?: string | null;
    remote_modified_at?: string | null;
}

export interface CommentRecord {
    id: number;
    site_id: number;
    remote_id: number;
    author_name?: string | null;
    author_email?: string | null;
    body?: string | null;
    status?: string | null;
    remote_created_at?: string | null;
}

export interface TaxonomyTermRecord {
    id: number;
    site_id: number;
    remote_id: number;
    taxonomy: string;
    name: string;
    slug?: string | null;
    description?: string | null;
}

export interface SeoAuditRecord {
    id: number;
    site_id: number;
    status: string;
    failure?: string | null;
    completed_at?: string | null;
}

export interface SeoFindingRecord {
    id: number;
    seo_audit_id: number;
    synced_content_id: number;
    code: string;
    severity: string;
    recommendation: string;
    status: string;
}

export interface OperationRecord {
    id: number;
    type?: string;
    status?: string;
    state?: string;
    created_at?: string;
    updated_at?: string;
    failure?: string | null;
}

export interface MemberRecord {
    id: number;
    user_id?: number;
    status: string;
    user?: { id: number; name: string; email: string };
}

export interface RoleRecord {
    id: number;
    name: string;
    permissions?: Array<{ id?: number; name: string }>;
}

const e = encodeURIComponent;

/**
 * Typed paths discovered from the live parallel Issue #257 worker PRs.
 * These builders do not imply availability. The runtime enables a screen only
 * when the integration authority advertises the endpoint in FrontendContext.
 */
export const workerApi = {
    connector: {
        source: 'PR #260',
        sites: (tenant: string) => `/api/tenants/${e(tenant)}/sites`,
        site: (tenant: string, site: number | string) => `/api/tenants/${e(tenant)}/sites/${e(String(site))}`,
        connector: (tenant: string, site: number | string) => `/api/tenants/${e(tenant)}/sites/${e(String(site))}/connector`,
        pairing: (tenant: string, site: number | string) => `/api/tenants/${e(tenant)}/sites/${e(String(site))}/pairing`,
        verify: (tenant: string, site: number | string) => `/api/tenants/${e(tenant)}/sites/${e(String(site))}/verify`,
        sync: (tenant: string, site: number | string) => `/api/tenants/${e(tenant)}/sites/${e(String(site))}/sync`,
        audit: (tenant: string, site: number | string) => `/api/tenants/${e(tenant)}/sites/${e(String(site))}/audits`,
        findings: (tenant: string, audit: number | string) => `/api/tenants/${e(tenant)}/audits/${e(String(audit))}/findings`,
        aiProvider: (tenant: string) => `/api/tenants/${e(tenant)}/ai/provider`,
        approval: (tenant: string, approval: number | string) => `/api/tenants/${e(tenant)}/approvals/${e(String(approval))}`,
        executionReceipt: (tenant: string, execution: number | string) => `/api/tenants/${e(tenant)}/executions/${e(String(execution))}/receipt`,
    },
    content: {
        source: 'PR #263',
        base: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}`,
        posts: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/content/post`,
        pages: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/content/page`,
        media: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/media`,
        comments: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/comments`,
        taxonomy: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/taxonomy`,
        sync: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/sync`,
        conflicts: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/conflicts`,
        export: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/transfers/export`,
        import: (tenant: string, site: number | string) => `/api/v1/tenants/${e(tenant)}/sites/${e(String(site))}/transfers/import`,
    },
    operations: {
        source: 'PR #264',
        base: (tenant: string) => `/tenants/${e(tenant)}/admin`,
        members: (tenant: string) => `/tenants/${e(tenant)}/admin/members`,
        roles: (tenant: string) => `/tenants/${e(tenant)}/admin/roles`,
        sessions: (tenant: string) => `/tenants/${e(tenant)}/admin/sessions`,
        settings: (tenant: string) => `/tenants/${e(tenant)}/admin/settings`,
        schedules: (tenant: string) => `/tenants/${e(tenant)}/admin/schedules`,
        automations: (tenant: string) => `/tenants/${e(tenant)}/admin/automations`,
        operations: (tenant: string) => `/tenants/${e(tenant)}/admin/operations`,
        syncOperations: (tenant: string) => `/tenants/${e(tenant)}/admin/sync-operations`,
        backups: (tenant: string) => `/tenants/${e(tenant)}/admin/backups`,
        logs: (tenant: string) => `/tenants/${e(tenant)}/admin/logs`,
        diagnostics: (tenant: string) => `/tenants/${e(tenant)}/admin/diagnostics`,
        report: (tenant: string, report: string) => `/tenants/${e(tenant)}/admin/reports/${e(report)}`,
        reportExports: (tenant: string) => `/tenants/${e(tenant)}/admin/reports/exports`,
    },
} as const;
