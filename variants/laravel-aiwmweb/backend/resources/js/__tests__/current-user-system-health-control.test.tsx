import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    CURRENT_USER_SYSTEM_HEALTH_OPERATION,
    CurrentUserSystemHealthControl,
} from '../current-user-system-health-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'diagnostics.view'],
    tenantSlugs: string[] = [slug],
    userId = 10,
): FrontendContext => ({
    user: { id: userId, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: tenantSlugs.map((tenantSlug) => ({ slug: tenantSlug, name: tenantSlug })),
    permissions,
    connectors: [],
    capabilities: {},
    api: { 'untrusted.system-health': '/tenants/beta/system-health' },
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <CurrentUserSystemHealthControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-IDEN-FC900E61B8 current-user System health control', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="topbar-actions"></div>';
    });

    afterEach(() => {
        cleanup();
        document.body.innerHTML = '';
    });

    it('renders only the canonical read-only destination for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Open System health/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/system-health');
        expect(link).toHaveAttribute('title', 'Services and database status');
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_SYSTEM_HEALTH_OPERATION);
        expect(CURRENT_USER_SYSTEM_HEALTH_OPERATION).toBe('AIMW-IDEN-FC900E61B8');
        expect(link.getAttribute('href')).not.toContain('beta');
    });

    it('URL-encodes server-derived tenant identity instead of interpreting a caller-controlled path', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /Open System health/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/system-health');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/system-health');
    });

    it('fails closed independently when either required permission is missing', () => {
        const tenantOnly = renderControl(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: /Open System health/i })).not.toBeInTheDocument();
        tenantOnly.unmount();

        renderControl(context('alpha', ['diagnostics.view']));
        expect(screen.queryByRole('link', { name: /Open System health/i })).not.toBeInTheDocument();
    });

    it('accepts the canonical wildcard permission without weakening tenant identity checks', () => {
        renderControl(context('alpha', ['*']));
        expect(screen.getByRole('link', { name: /Open System health/i })).toHaveAttribute('href', '/tenants/alpha/system-health');
    });

    it('fails closed when active tenant is not in server-provided memberships or user identity is invalid', () => {
        const foreign = renderControl(context('alpha', ['tenant.view', 'diagnostics.view'], ['beta']));
        expect(screen.queryByRole('link', { name: /Open System health/i })).not.toBeInTheDocument();
        foreign.unmount();

        renderControl(context('alpha', ['tenant.view', 'diagnostics.view'], ['alpha'], 0));
        expect(screen.queryByRole('link', { name: /Open System health/i })).not.toBeInTheDocument();
    });

    it('does not consume API/action contracts as alternate tenant or mutation authority', () => {
        const value = context();
        value.actions['system-health'] = {
            endpoint: '/tenants/beta/system-health',
            method: 'POST',
        };
        renderControl(value);

        const link = screen.getByRole('link', { name: /Open System health/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/system-health');
        expect(link).not.toHaveAttribute('data-method');
    });
});
