import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    CURRENT_USER_SETTINGS_OPERATION,
    CurrentUserSettingsControl,
} from '../current-user-settings-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view'],
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
            <CurrentUserSettingsControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-IDEN-A1064E5A5E current-user Settings control', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="topbar-actions"></div>';
    });

    afterEach(() => {
        cleanup();
        document.body.innerHTML = '';
    });

    it('renders the canonical Settings link for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Open Settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/settings');
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_SETTINGS_OPERATION);
        expect(CURRENT_USER_SETTINGS_OPERATION).toBe('AIMW-IDEN-A1064E5A5E');
    });

    it('encodes the authoritative tenant slug and cannot synthesize a foreign tenant destination', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /Open Settings/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/settings');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/settings');
    });

    it('fails closed when tenant.view is missing', () => {
        renderControl(context('alpha', []));

        expect(screen.queryByRole('link', { name: /Open Settings/i })).not.toBeInTheDocument();
    });
});
