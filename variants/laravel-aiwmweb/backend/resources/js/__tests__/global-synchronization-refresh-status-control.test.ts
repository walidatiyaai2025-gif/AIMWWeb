import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import {
    GLOBAL_SYNCHRONIZATION_REFRESH_STATUS_OPERATION_ID,
    globalSynchronizationRefreshEndpoints,
    validateGlobalSynchronizationRefresh,
} from '../global-synchronization-refresh-status-control';

type Context = FrontendContext & { active_site?: { id: number; name: string } | null };
function context(): Context {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view', 'sync.view'],
        connectors: [],
        capabilities: {},
        api: {
            sync: '/api/v1/tenants/alpha/sites/7/sync',
            explorer: '/api/v1/tenants/alpha/sites/7/explorer',
        },
        actions: {},
        active_site: { id: 7, name: 'Alpha Site' },
    };
}

describe('AIMW-SYNC-9504A57CAE GlobalSynchronization RefreshStatusAsync', () => {
    it('binds only the trusted tenant/site endpoints and operation ID', () => {
        expect(GLOBAL_SYNCHRONIZATION_REFRESH_STATUS_OPERATION_ID).toBe('AIMW-SYNC-9504A57CAE');
        expect(globalSynchronizationRefreshEndpoints(context())).toEqual({
            siteId: 7,
            sync: '/api/v1/tenants/alpha/sites/7/sync',
            explorer: '/api/v1/tenants/alpha/sites/7/explorer',
        });
    });

    it('refuses foreign tenant, wrong selected site, missing permission and disabled capabilities', () => {
        const invalidTenant = context(); invalidTenant.tenant.slug = 'alpha/../beta';
        expect(globalSynchronizationRefreshEndpoints(invalidTenant)).toBeNull();
        const foreignSite = context(); foreignSite.api.sync = '/api/v1/tenants/alpha/sites/8/sync';
        expect(globalSynchronizationRefreshEndpoints(foreignSite)).toBeNull();
        const foreignTenant = context(); foreignTenant.api.explorer = '/api/v1/tenants/beta/sites/7/explorer';
        expect(globalSynchronizationRefreshEndpoints(foreignTenant)).toBeNull();
        const none = context(); none.active_site = null;
        expect(globalSynchronizationRefreshEndpoints(none)).toBeNull();
        const denied = context(); denied.permissions = denied.permissions.filter((name) => name !== 'content.view');
        expect(globalSynchronizationRefreshEndpoints(denied)).toBeNull();
        const unavailable = context(); unavailable.capabilities = { sync: { state: 'site_disconnected' } };
        expect(globalSynchronizationRefreshEndpoints(unavailable)).toBeNull();
    });

    it('rereads factual empty state and never fabricates a completed run', () => {
        expect(validateGlobalSynchronizationRefresh(7, {
            total: 0, meta: { site: { id: 7, name: 'Alpha' }, last_synchronized_at: null },
        }, { data: [], total: 0 })).toMatchObject({
            cachedItems: 0, latestRun: null, totalRuns: 0, lastSynchronizedAt: null,
        });
        expect(validateGlobalSynchronizationRefresh(7, {
            total: 11, meta: { site: { id: 7, name: 'Alpha', connection_status: 'connected' } },
        }, { data: [{ id: 19, site_id: 7, state: 'failed', mode: 'full' }], total: 1 }).latestRun?.state).toBe('failed');
    });

    it('rejects cross-site or malformed read results', () => {
        const snapshot = { total: 3, meta: { site: { id: 7, name: 'Alpha' } } };
        expect(() => validateGlobalSynchronizationRefresh(7, { ...snapshot, meta: { site: { id: 8, name: 'Beta' } } },
            { data: [], total: 0 })).toThrow(/selected site/);
        expect(() => validateGlobalSynchronizationRefresh(7, snapshot,
            { data: [{ id: 1, site_id: 9, state: 'completed', mode: 'full' }], total: 1 })).toThrow(/another site/);
        expect(() => validateGlobalSynchronizationRefresh(7, { ...snapshot, total: -1 },
            { data: [], total: 0 })).toThrow(/malformed/);
    });

    it('uses only real GETs, preserves failures and supports Arabic labels', () => {
        const ui = readFileSync('resources/js/global-synchronization-refresh-status-control.tsx', 'utf8');
        const app = readFileSync('resources/js/app.tsx', 'utf8');
        expect(ui).toContain('data-canonical-operation={GLOBAL_SYNCHRONIZATION_REFRESH_STATUS_OPERATION_ID}');
        expect(ui).toContain('apiRequest<SiteSnapshot>(endpoints.explorer)');
        expect(ui).toContain('apiRequest<SyncHistory>(endpoints.sync)');
        expect(ui).toContain('role="alert"');
        expect(ui).toContain("'ar' ? 'تحديث الحالة'");
        expect(ui).not.toContain("method: 'POST'");
        expect(app).toContain('<GlobalSynchronizationRefreshStatusControl context={context} />');
    });
});
