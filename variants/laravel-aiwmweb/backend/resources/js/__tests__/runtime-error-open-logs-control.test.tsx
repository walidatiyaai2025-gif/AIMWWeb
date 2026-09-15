import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    RUNTIME_ERROR_OPEN_LOGS_OPERATION_ID,
    RuntimeErrorOpenLogsControl,
    runtimeErrorLogsHref,
} from '../runtime-error-open-logs-control';

describe('AIMW-OPER-21EC1BDE45 global runtime error Open logs control', () => {
    it('derives the existing guarded tenant logs alias from the current tenant path', () => {
        expect(runtimeErrorLogsHref('/tenants/alpha/module/posts')).toBe('/tenants/alpha/logs');
        expect(runtimeErrorLogsHref('/tenants/alpha')).toBe('/tenants/alpha/logs');
    });

    it('encodes the tenant slug and does not permit a decoded path escape', () => {
        expect(runtimeErrorLogsHref('/tenants/alpha%20team/module/posts')).toBe('/tenants/alpha%20team/logs');
        expect(runtimeErrorLogsHref('/tenants/alpha%2F..%2Fbeta/module/posts')).toBeNull();
        expect(runtimeErrorLogsHref('/tenants/alpha%5Cbeta/module/posts')).toBeNull();
        expect(runtimeErrorLogsHref('/tenants/%E0%A4%A/module/posts')).toBeNull();
    });

    it.each(['/', '/Error', '/logs', '/admin', '/tenant/alpha'])('fails closed outside a tenant-scoped path: %s', (pathname) => {
        expect(runtimeErrorLogsHref(pathname)).toBeNull();
    });

    it('renders the canonical navigation marker and source-equivalent label when tenant scope is known', () => {
        render(<RuntimeErrorOpenLogsControl pathname="/tenants/alpha/module/logs" />);

        const link = screen.getByRole('link', { name: 'Open logs' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/logs');
        expect(link).toHaveAttribute('data-canonical-operation', RUNTIME_ERROR_OPEN_LOGS_OPERATION_ID);
        expect(RUNTIME_ERROR_OPEN_LOGS_OPERATION_ID).toBe('AIMW-OPER-21EC1BDE45');
    });

    it('renders nothing when the global error occurs without an authoritative tenant path', () => {
        render(<RuntimeErrorOpenLogsControl pathname="/Error" />);
        expect(screen.queryByRole('link', { name: 'Open logs' })).not.toBeInTheDocument();
    });
});
