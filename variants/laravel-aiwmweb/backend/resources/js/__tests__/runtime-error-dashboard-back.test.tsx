import React from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import {
    RUNTIME_ERROR_BACK_TO_DASHBOARD_OPERATION_ID,
    RuntimeErrorDashboardBackControl,
} from '../runtime-error-dashboard-back-control';

const originalPath = `${window.location.pathname}${window.location.search}${window.location.hash}`;

afterEach(() => {
    window.history.replaceState({}, '', originalPath || '/');
});

describe('AIMW-PLAT-AF47A254FE runtime Back to dashboard navigation', () => {
    it('renders the source-equivalent native root anchor with the exact canonical marker', () => {
        window.history.replaceState({}, '', '/tenants/alpha/logs?site=17#runtime-error');

        render(<RuntimeErrorDashboardBackControl />);

        const link = screen.getByRole('link', { name: 'Back to dashboard' });
        expect(link.tagName).toBe('A');
        expect(link).toHaveAttribute('href', '/');
        expect(link).toHaveAttribute(
            'data-canonical-operation',
            RUNTIME_ERROR_BACK_TO_DASHBOARD_OPERATION_ID,
        );
        expect(RUNTIME_ERROR_BACK_TO_DASHBOARD_OPERATION_ID).toBe('AIMW-PLAT-AF47A254FE');
    });

    it('does not carry tenant, resource, query, fragment, API, or mutation state into the dashboard destination', () => {
        window.history.replaceState({}, '', '/tenants/foreign-tenant/sites/991?token=do-not-copy#failure');

        render(<RuntimeErrorDashboardBackControl />);

        const href = screen.getByRole('link', { name: 'Back to dashboard' }).getAttribute('href');
        expect(href).toBe('/');
        expect(href).not.toContain('foreign-tenant');
        expect(href).not.toContain('991');
        expect(href).not.toContain('token');
        expect(href).not.toContain('?');
        expect(href).not.toContain('#');
        expect(href).not.toContain('/api/');
    });
});
