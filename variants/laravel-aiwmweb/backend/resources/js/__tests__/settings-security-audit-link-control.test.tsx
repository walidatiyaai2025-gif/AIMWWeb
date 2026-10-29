import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_SECURITY_AUDIT_LINK_OPERATION_ID,
    SettingsSecurityAuditLinkControl,
} from '../settings-security-audit-link-control';

const context = (permissions: string[]): FrontendContext => ({
    user: { id: 10, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

beforeEach(() => {
    window.localStorage.clear();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-ED2F1E1572 Settings security audit link', () => {
    it('renders the exact read-only security-audit destination without a network side effect', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <LocaleProvider>
                <SettingsSecurityAuditLinkControl context={context(['tenant.view', 'settings.manage'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: 'Open security audit' });
        expect(link).toHaveAttribute('href', '/admin/security-audit');
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_SECURITY_AUDIT_LINK_OPERATION_ID);
        expect(SETTINGS_SECURITY_AUDIT_LINK_OPERATION_ID).toBe('AIMW-CONT-ED2F1E1572');
        expect(screen.getByText('Security audit trail')).toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('renders Arabic copy while preserving the exact canonical destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <LocaleProvider>
                <SettingsSecurityAuditLinkControl context={context(['tenant.view', 'settings.manage'])} />
            </LocaleProvider>,
        );

        expect(screen.getByRole('link', { name: 'فتح سجل التدقيق' }))
            .toHaveAttribute('href', '/admin/security-audit');
    });

    it('fails closed without both Settings visibility and management permission', () => {
        const { rerender } = render(
            <LocaleProvider>
                <SettingsSecurityAuditLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('link', { name: 'Open security audit' })).not.toBeInTheDocument();

        rerender(
            <LocaleProvider>
                <SettingsSecurityAuditLinkControl context={context(['settings.manage'])} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('link', { name: 'Open security audit' })).not.toBeInTheDocument();
    });
});
