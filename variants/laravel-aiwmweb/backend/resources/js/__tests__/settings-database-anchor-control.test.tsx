import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_DATABASE_ANCHOR_OPERATION_ID,
    SETTINGS_DATABASE_TARGET_ID,
    SettingsDatabaseAnchorControl,
} from '../settings-database-anchor-control';

const context = (permissions: string[], slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
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

describe('AIMW-CONT-932A09E4A9 Settings database anchor', () => {
    it('renders the canonical same-page database link with a real target and no HTTP mutation', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SettingsDatabaseAnchorControl context={context(['tenant.view', 'diagnostics.view'])} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /^Database$/i });
        expect(link).toHaveAttribute('href', '#database');
        expect(link).toHaveAttribute('aria-controls', SETTINGS_DATABASE_TARGET_ID);
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_DATABASE_ANCHOR_OPERATION_ID);
        expect(SETTINGS_DATABASE_ANCHOR_OPERATION_ID).toBe('AIMW-CONT-932A09E4A9');

        const target = document.getElementById(SETTINGS_DATABASE_TARGET_ID);
        expect(target).not.toBeNull();
        expect(target).toHaveAttribute('data-settings-section', 'database');
        expect(screen.getByText('Database configuration')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open System Health' })).toHaveAttribute('href', '/tenants/alpha/system-health');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('preserves the fragment in Arabic and hides diagnostics navigation without diagnostics.view', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SettingsDatabaseAnchorControl context={context(['tenant.view'])} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: /^قاعدة البيانات$/ });
        expect(link).toHaveAttribute('href', '#database');
        expect(document.getElementById('database')).not.toBeNull();
        expect(screen.queryByRole('link', { name: 'فتح صحة النظام' })).not.toBeInTheDocument();
    });

    it('fails closed without tenant.view and exposes neither navigation nor target section', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <SettingsDatabaseAnchorControl context={context([])} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        expect(screen.queryByRole('link', { name: /^Database$/i })).not.toBeInTheDocument();
        expect(document.getElementById('database')).toBeNull();
    });
});
