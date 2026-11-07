import { describe, expect, it, vi } from 'vitest';
import { workspaceRoutes, type FrontendContext } from '../core';
import {
    liveAuditPlan, runReadOnlyWorkspaceAudit, safeAuditEndpoint,
    type LiveAuditResult,
} from '../runtime-workspace-audit';
import { safeInspectorBundle, type InspectorServer } from '../runtime-inspector-panel';

function context(): FrontendContext {
    return {
        user: { id: 17, name: 'PRIVATE USER NAME', email: 'PRIVATE-EMAIL@example.test' },
        tenant: { slug: 'wordpress', name: 'PRIVATE TENANT NAME' },
        tenants: [{ slug: 'wordpress', name: 'PRIVATE TENANT NAME' }],
        permissions: ['*'],
        connectors: [],
        capabilities: {},
        api: {
            dashboard: '/api/dashboard',
            sites: '/api/tenants/wordpress/sites',
            posts: '/api/v1/tenants/wordpress/sites/1/content/post',
            pages: 'https://attacker.test/api/tenants/wordpress/pages?token=CANARY_SECRET',
            media: '/api/tenants/other/media',
            comments: '/api/tenants/wordpress/comments?secret=CANARY_SECRET',
            categories: '/api/tenants/wordpress/items/:id',
        },
        actions: { 'posts.publish': { endpoint: '/api/tenants/wordpress/publish', method: 'POST' } },
    };
}

const server: InspectorServer = {
    schema: 'aiwm.runtime-inspector.v1',
    generated_at: '2026-11-07T04:00:00+00:00',
    safety: { redacted: true, read_only: true },
    release: { version: '1.0.381', sha: 'ee2365338ede4ec5d0e015e268c593a06a579d71' },
    runtime: {
        php_version: '8.3', laravel_version: '13.37.0', database: 'reachable',
        database_driver: 'mysql', queue_driver: 'sync', cache_driver: 'file',
        extensions: { sodium: true },
    },
    wordpress_plugin: {
        name: 'AIMW Connector', bundle_version: '0.2.3',
        download_path: '/downloads/aimw-connector.zip', download_available: true,
        install_target: 'wordpress_plugin',
    },
    site_count: 0, sites_truncated: false, sites: [],
};

describe('explicit all-workspace read-only live audit', () => {
    it('rejects cross-tenant, external, query and path-traversal targets', () => {
        for (const value of [
            'https://evil.test/api/dashboard',
            '//evil.test/api/dashboard',
            '/api/tenants/other/sites',
            '/api/tenants/wordpress/sites?token=CANARY',
            '/api/tenants/wordpress/../other',
            '/api/tenants/wordpress/%2e%2e/other',
            '/api/tenants/wordpress/%2fother',
            '/api/tenants/wordpress/sites#fragment',
            '/api/tenants/wordpress/admin:destroy',
        ]) {
            expect(safeAuditEndpoint(value, 'wordpress')).toBe(false);
        }
        expect(safeAuditEndpoint('/api/dashboard', 'wordpress')).toBe(true);
        expect(safeAuditEndpoint('/api/v1/tenants/wordpress/sites/1/content/post', 'wordpress')).toBe(true);
    });

    it('allows only authoritative tenant-local GET paths and fixed read query strings', () => {
        const valid = [
            '/api/dashboard?tenant=wordpress',
            '/tenants/wordpress/admin/operations',
            '/tenants/wordpress/admin/operations-hub',
            '/tenants/wordpress/admin/automations',
            '/tenants/wordpress/admin/schedules',
            '/tenants/wordpress/admin/logs',
            '/tenants/wordpress/admin/diagnostics',
            '/tenants/wordpress/admin/backups',
            '/tenants/wordpress/admin/members',
            '/tenants/wordpress/admin/roles',
            '/tenants/wordpress/admin/sessions',
            '/tenants/wordpress/route-api/report-exports',
            '/tenants/wordpress/route-api/site-operations',
            '/tenants/wordpress/route-api/site-reliability',
            '/tenants/wordpress/route-api/billing-overview',
            '/tenants/wordpress/route-api/account-profile',
            '/tenants/wordpress/configuration-validation/report',
            '/api/v1/tenants/wordpress/sites/1/taxonomy?taxonomy=category',
            '/api/v1/tenants/wordpress/sites/123/taxonomy?taxonomy=post_tag',
        ];
        for (const endpoint of valid) {
            expect(safeAuditEndpoint(endpoint, 'wordpress'), endpoint).toBe(true);
        }
        const invalid = [
            '/api/dashboard?tenant=other',
            '/api/dashboard?tenant=wordpress&delete=true',
            '/api/v1/tenants/wordpress/sites/0/taxonomy?taxonomy=category',
            '/api/v1/tenants/wordpress/sites/1/taxonomy?taxonomy=private',
            '/api/v1/tenants/wordpress/sites/1/taxonomy?taxonomy=post_tag&limit=100',
            '/tenants/other/admin/backups',
            '/tenants/wordpress/admin/backups/1/restore',
            '/tenants/wordpress/admin/automations/1/trigger',
            '/tenants/wordpress/configuration-validation/run',
            '/tenants/wordpress/admin/backups?force=1',
            '/tenants/wordpress/admin/../admin/members',
            '/tenants/wordpress/admin/%2e%2e/diagnostics',
            '/tenants/wordpress/admin/logs#secret',
            '//tenants/wordpress/admin/backups',
            'https://attacker.example/tenants/wordpress/admin/operations',
        ];
        for (const endpoint of invalid) {
            expect(safeAuditEndpoint(endpoint, 'wordpress'), endpoint).toBe(false);
        }
    });

    it('distinguishes missing site selection from an actual missing API contract', () => {
        const api = { dashboard: '/api/dashboard?tenant=wordpress' };
        const noSite = liveAuditPlan({ ...context(), api, active_site: null });
        for (const key of ['explorer', 'posts', 'pages', 'media', 'comments', 'taxonomy',
            'categories', 'tags', 'wp-users', 'sync', 'seo-audit']) {
            expect(noSite.find(entry => entry.key === key)?.reason, key)
                .toBe('site_selection_required');
        }
        expect(noSite.find(entry => entry.key === 'site-connect')?.reason)
            .toBe('navigation_only');

        const selected = liveAuditPlan({
            ...context(), api, active_site: { id: 1, name: 'site', status: 'connected' },
        });
        expect(selected.find(entry => entry.key === 'posts')?.reason).toBe('missing_contract');
        expect(selected.every(entry => entry.tested_write_actions === 0)).toBe(true);
    });

    it('probes documented production context paths without executing writes', async () => {
        const realistic = {
            ...context(),
            api: {
                dashboard: '/api/dashboard?tenant=wordpress',
                sites: '/api/tenants/wordpress/sites',
                automation: '/tenants/wordpress/admin/automations',
                operations: '/tenants/wordpress/admin/operations',
                backups: '/tenants/wordpress/admin/backups',
                roles: '/tenants/wordpress/admin/roles',
                'configuration-validation': '/tenants/wordpress/configuration-validation/report',
                categories: '/api/v1/tenants/wordpress/sites/1/taxonomy?taxonomy=category',
                tags: '/api/v1/tenants/wordpress/sites/1/taxonomy?taxonomy=post_tag',
            },
        };
        const plan = liveAuditPlan(realistic);
        for (const key of ['dashboard', 'automation', 'operations', 'backups',
            'roles', 'configuration-validation', 'categories', 'tags']) {
            expect(plan.find(row => row.key === key)?.reason, key).toBe('read_ok');
        }
        const calls: string[] = [];
        const requester = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            calls.push(String(input));
            expect(init?.method).toBe('GET');
            return new Response('{"items":[]}', {
                status: 200, headers: { 'content-type': 'application/json' },
            });
        });
        const report = await runReadOnlyWorkspaceAudit(realistic, undefined, requester as typeof fetch);
        expect(report.safety.write_actions_executed).toBe(false);
        expect(report.totals.failed_read).toBe(0);
        expect(report.totals.passed_read).toBeGreaterThanOrEqual(8);
        expect(calls).toContain('/tenants/wordpress/admin/backups');
        expect(calls).toContain('/api/dashboard?tenant=wordpress');
        expect(calls.every(endpoint => safeAuditEndpoint(endpoint, 'wordpress'))).toBe(true);
        expect(report.totals.write_actions_untested).toBe(46);
    });

    it('classifies every route and preserves untested mutations without starting requests', () => {
        const plan = liveAuditPlan(context());
        expect(plan).toHaveLength(workspaceRoutes.length);
        expect(plan.find(row => row.key === 'dashboard')?.reason).toBe('read_ok');
        expect(plan.find(row => row.key === 'pages')?.reason).toBe('unsafe_endpoint');
        expect(plan.find(row => row.key === 'media')?.reason).toBe('unsafe_endpoint');
        expect(plan.find(row => row.key === 'comments')?.reason).toBe('unsafe_endpoint');
        expect(plan.find(row => row.key === 'categories')?.reason).toBe('parameterized_endpoint');
        expect(plan.find(row => row.key === 'welcome')?.reason).toBe('navigation_only');
        expect(plan.every(row => row.tested_write_actions === 0)).toBe(true);
    });

    it('GET-probes only allowlisted tenant APIs, checks JSON not SPA HTML and reports failures', async () => {
        const requested: string[] = [];
        const requester = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            requested.push(String(input));
            expect(init?.method).toBe('GET');
            expect(init?.credentials).toBe('same-origin');
            expect(init?.redirect).toBe('error');
            const url = String(input);
            if (url.includes('/content/post')) return new Response('<html>fallback</html>', {
                status: 200, headers: { 'content-type': 'text/html' },
            });
            if (url.endsWith('/sites')) return new Response('{"error":"forbidden"}', {
                status: 403, headers: { 'content-type': 'application/json' },
            });
            return new Response('{"ok":true}', {
                status: 200, headers: { 'content-type': 'application/json' },
            });
        });
        const audit = await runReadOnlyWorkspaceAudit(context(), undefined, requester as typeof fetch);
        expect(audit.safety).toEqual({ read_only: true, request_methods: ['GET'], write_actions_executed: false });
        expect(audit.totals.routes).toBe(workspaceRoutes.length);
        expect(audit.totals.passed_read).toBe(1);
        expect(audit.totals.failed_read).toBe(2);
        expect(audit.workspaces.find(x => x.key === 'posts')).toMatchObject({
            state: 'failed_read', reason: 'invalid_response', http_status: 200,
        });
        expect(audit.workspaces.find(x => x.key === 'sites')).toMatchObject({
            state: 'failed_read', reason: 'http_error', http_status: 403,
        });
        expect(requested).toEqual(expect.arrayContaining([
            '/api/dashboard', '/api/tenants/wordpress/sites', '/api/v1/tenants/wordpress/sites/1/content/post',
        ]));
        expect(requester).toHaveBeenCalledTimes(3);
        expect(JSON.stringify(audit)).not.toContain('CANARY_SECRET');
        expect(JSON.stringify(audit)).not.toContain('PRIVATE USER NAME');
    });

    it('never passes a 200 JSON-header response whose bounded body is invalid', async () => {
        const cases = [
            { label: 'malformed', body: '{invalid', contentType: 'application/json' },
            { label: 'empty', body: '', contentType: 'application/json' },
            { label: 'primitive', body: '"not-a-document"', contentType: 'application/json' },
            { label: 'null', body: 'null', contentType: 'application/json' },
            { label: 'oversized', body: JSON.stringify({ private: 'CANARY_SECRET'.repeat(105000) }), contentType: 'application/json' },
            { label: 'bad content type', body: '{"ok":true}', contentType: 'text/html' },
        ];
        for (const testCase of cases) {
            const requester = vi.fn(async () => new Response(testCase.body, {
                status: 200, headers: { 'content-type': testCase.contentType },
            }));
            const audit = await runReadOnlyWorkspaceAudit(
                { ...context(), api: { dashboard: '/api/dashboard' } }, undefined, requester as typeof fetch);
            const dashboard = audit.workspaces.find(entry => entry.key === 'dashboard');
            expect(dashboard, testCase.label).toMatchObject({
                state: 'failed_read', reason: 'invalid_response', http_status: 200,
            });
            expect(audit.totals.passed_read, testCase.label).toBe(0);
            expect(requester, testCase.label).toHaveBeenCalledTimes(1);
            expect(JSON.stringify(audit), testCase.label).not.toContain('CANARY_SECRET');
        }
    });

    it('accepts a bounded JSON object or array and rejects false Content-Length hints', async () => {
        for (const body of ['{"ok":true}', '[]']) {
            const result = await runReadOnlyWorkspaceAudit(
                { ...context(), api: { dashboard: '/api/dashboard' } }, undefined,
                (async () => new Response(body, {
                    status: 200, headers: { 'content-type': 'application/json; charset=utf-8' },
                })) as typeof fetch);
            expect(result.workspaces.find(entry => entry.key === 'dashboard')).toMatchObject({
                state: 'passed_read', reason: 'read_ok', http_status: 200,
            });
        }
        const result = await runReadOnlyWorkspaceAudit(
            { ...context(), api: { dashboard: '/api/dashboard' } }, undefined,
            (async () => new Response('{"ok":true}', {
                status: 200,
                headers: { 'content-type': 'application/json', 'content-length': '2000000' },
            })) as typeof fetch);
        expect(result.workspaces.find(entry => entry.key === 'dashboard')).toMatchObject({
            state: 'failed_read', reason: 'invalid_response', http_status: 200,
        });
    });

    it('sanitized export includes status-only audit and never a URL or supplied secret', async () => {
        const audit = await runReadOnlyWorkspaceAudit(context(), undefined,
            (async () => new Response('{"ok":true}', {
                status: 200, headers: { 'content-type': 'application/json' },
            })) as typeof fetch);
        const corrupt: LiveAuditResult = {
            ...audit,
            workspaces: [...audit.workspaces, {
                key: 'CANARY_PRIVATE?token=SECRET', state: 'passed_read',
                reason: 'read_ok', http_status: 200, duration_ms: 5,
                declared_write_actions: 1, advertised_write_actions: 1, tested_write_actions: 0,
            }],
        };
        const exported = safeInspectorBundle(server, [], context(), corrupt);
        const raw = JSON.stringify(exported);
        expect(exported.server.wordpress_plugin).toMatchObject({
            name: 'AIMW Connector', bundle_version: '0.2.3',
            install_target: 'wordpress_plugin',
        });
        expect(exported.workspace_live_audit?.workspaces).toHaveLength(workspaceRoutes.length);
        expect(exported.workspace_live_audit?.safety.write_actions_executed).toBe(false);
        for (const secret of ['CANARY_PRIVATE', 'CANARY_SECRET', 'PRIVATE USER NAME', 'PRIVATE-EMAIL',
            'PRIVATE TENANT NAME', 'attacker.test', 'api/tenants', 'publish']) {
            expect(raw).not.toContain(secret);
        }
    });
});
