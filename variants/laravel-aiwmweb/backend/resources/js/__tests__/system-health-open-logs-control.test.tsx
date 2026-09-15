import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SYSTEM_HEALTH_OPEN_LOGS_OPERATION_ID,
    SystemHealthOpenLogsControl,
} from '../system-health-open-logs-control';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'diagnostics.view', 'operations.manage'],
    logsApi = `/tenants/${encodeURIComponent(slug)}/admin/logs`,
    tenantSlugs: string[] = [slug],
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
    permissions,
    connectors: [],
    capabilities: {},
    api: { logs: logsApi },
    actions: {},
});

function renderControl(value: FrontendContext, snapshotReady = true) {
    return render(
        <LocaleProvider>
            <SystemHealthOpenLogsControl context={value} snapshotReady={snapshotReady} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-CONT-553D999DDB System Health Open logs control', () => {
    it('renders only after a real snapshot and uses the authoritative tenant logs destination', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Open logs' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/logs');
        expect(link).toHaveAttribute('data-canonical-operation', SYSTEM_HEALTH_OPEN_LOGS_OPERATION_ID);
        expect(SYSTEM_HEALTH_OPEN_LOGS_OPERATION_ID).toBe('AIMW-CONT-553D999DDB');
        expect(link).not.toHaveAttribute('data-method');
    });

    it('fails closed while no successful System Health snapshot exists', () => {
        renderControl(context(), false);
        expect(screen.queryByRole('link', { name: 'Open logs' })).not.toBeInTheDocument();
    });

    it('fails closed when either diagnostics or logs endpoint permission is absent', () => {
        const diagnosticsMissing = renderControl(context('alpha', ['tenant.view', 'operations.manage']));
        expect(screen.queryByRole('link', { name: 'Open logs' })).not.toBeInTheDocument();
        diagnosticsMissing.unmount();

        renderControl(context('alpha', ['tenant.view', 'diagnostics.view']));
        expect(screen.queryByRole('link', { name: 'Open logs' })).not.toBeInTheDocument();
    });

    it('rejects a foreign or synthesized logs API contract', () => {
        renderControl(context('alpha', undefined, '/tenants/beta/admin/logs'));
        expect(screen.queryByRole('link', { name: 'Open logs' })).not.toBeInTheDocument();
    });

    it('URL-encodes the server-derived active tenant and never interprets it as another path', () => {
        renderControl(context('alpha/../beta'));
        const link = screen.getByRole('link', { name: 'Open logs' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/module/logs');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/module/logs');
    });

    it('fails closed when the active tenant is not in the server-provided membership set', () => {
        renderControl(context('alpha', undefined, '/tenants/alpha/admin/logs', ['beta']));
        expect(screen.queryByRole('link', { name: 'Open logs' })).not.toBeInTheDocument();
    });
});
