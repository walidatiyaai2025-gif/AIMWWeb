import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { CURRENT_USER_LOGS_OPERATION_ID, CurrentUserLogsControl } from '../current-user-logs-control';
import type { FrontendContext } from '../core';

const context = (
    slug = 'alpha',
    permissions: string[] = ['diagnostics.view', 'operations.manage'],
    api: Record<string, string> = { logs: '/tenants/alpha/admin/logs' },
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api,
    actions: {},
});

afterEach(() => {
    cleanup();
    window.history.replaceState({}, '', '/');
});

describe('AIMW-IDEN-CD4ADA5087 current-user Open logs control', () => {
    it('binds the exact canonical operation to the trusted active-tenant logs workspace', () => {
        render(<CurrentUserLogsControl context={context()} />);
        const link = screen.getByRole('link', { name: 'Open logs' });
        expect(CURRENT_USER_LOGS_OPERATION_ID).toBe('AIMW-IDEN-CD4ADA5087');
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_LOGS_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/logs');
        expect(document.querySelectorAll(`[data-canonical-operation="${CURRENT_USER_LOGS_OPERATION_ID}"]`)).toHaveLength(1);
    });

    it('derives and encodes the destination only from the trusted server context, never the browser pathname', () => {
        window.history.replaceState({}, '', '/tenants/beta/module/posts');
        const slug = 'alpha/../beta';
        render(<CurrentUserLogsControl context={context(slug, ['diagnostics.view', 'operations.manage'], { logs: '/tenants/alpha%2F..%2Fbeta/admin/logs' })} />);
        const link = screen.getByRole('link', { name: 'Open logs' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/module/logs');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/module/logs');
    });

    it.each([
        [['operations.manage'], { logs: '/tenants/alpha/admin/logs' }],
        [['diagnostics.view'], { logs: '/tenants/alpha/admin/logs' }],
        [['diagnostics.view', 'operations.manage'], {}],
        [['diagnostics.view', 'operations.manage'], { logs: '/tenants/beta/admin/logs' }],
    ])('fails closed when permissions or the exact server API contract are incomplete', (permissions, api) => {
        render(<CurrentUserLogsControl context={context('alpha', permissions as string[], api as Record<string, string>)} />);
        expect(screen.queryByRole('link', { name: 'Open logs' })).not.toBeInTheDocument();
    });
});
