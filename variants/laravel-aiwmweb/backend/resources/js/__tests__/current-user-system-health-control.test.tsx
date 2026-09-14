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
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
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

    it('renders the canonical System health link for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Open System health/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/system-health');
        expect(link).toHaveAttribute('title', 'Services and database status');
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_SYSTEM_HEALTH_OPERATION);
        expect(CURRENT_USER_SYSTEM_HEALTH_OPERATION).toBe('AIMW-IDEN-FC900E61B8');
    });

    it('derives and encodes the tenant destination without accepting a foreign tenant path', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /Open System health/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/system-health');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/system-health');
    });

    it('fails closed when diagnostics.view is missing even with tenant.view', () => {
        renderControl(context('alpha', ['tenant.view']));

        expect(screen.queryByRole('link', { name: /Open System health/i })).not.toBeInTheDocument();
    });

    it('fails closed when tenant.view is missing even with diagnostics.view', () => {
        renderControl(context('alpha', ['diagnostics.view']));

        expect(screen.queryByRole('link', { name: /Open System health/i })).not.toBeInTheDocument();
    });
});
