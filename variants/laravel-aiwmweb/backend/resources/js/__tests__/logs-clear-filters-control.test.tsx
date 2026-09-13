import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    LOGS_CLEAR_FILTERS_OPERATION_ID,
    LogsClearFiltersControl,
} from '../logs-clear-filters-control';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 1, name: 'Logs Operator', email: 'logs@example.test' },
    tenant: { slug, name: 'Logs Tenant' },
    tenants: [{ slug, name: 'Logs Tenant' }],
    permissions: ['tenant.view', 'operations.manage', 'diagnostics.view'],
    connectors: [],
    capabilities: {},
    api: { logs: `/tenants/${encodeURIComponent(slug)}/admin/logs` },
    actions: {},
});

describe('canonical logs clear-filters control', () => {
    it('renders the exact canonical control as a real tenant-scoped navigation reset', () => {
        render(
            <LocaleProvider>
                <LogsClearFiltersControl context={context()} />
            </LocaleProvider>,
        );

        const control = screen.getByRole('link', { name: 'Clear filters' });
        expect(control).toHaveAttribute('data-canonical-operation', LOGS_CLEAR_FILTERS_OPERATION_ID);
        expect(LOGS_CLEAR_FILTERS_OPERATION_ID).toBe('AIMW-CONT-83908F2D7C');
        expect(control).toHaveAttribute('href', '/tenants/alpha/module/logs');
    });

    it('derives the reset destination from the active tenant context and URL-encodes it', () => {
        render(
            <LocaleProvider>
                <LogsClearFiltersControl context={context('tenant with space')} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('link', { name: 'Clear filters' }))
            .toHaveAttribute('href', '/tenants/tenant%20with%20space/module/logs');
    });
});
