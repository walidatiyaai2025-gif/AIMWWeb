import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    CURRENT_USER_ACCOUNT_PROFILE_OPERATION,
    CurrentUserAccountProfileControl,
} from '../current-user-account-profile-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view'],
    profileApi?: string,
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {
        'account.profile': profileApi ?? `/tenants/${encodeURIComponent(slug)}/route-api/account-profile`,
    },
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <CurrentUserAccountProfileControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-IDEN-9B043B1FAE current-user My account control', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="topbar-actions"></div>';
    });

    afterEach(() => {
        cleanup();
        document.body.innerHTML = '';
    });

    it('renders the canonical My account link for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Open My account/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/account/profile');
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_ACCOUNT_PROFILE_OPERATION);
        expect(CURRENT_USER_ACCOUNT_PROFILE_OPERATION).toBe('AIMW-IDEN-9B043B1FAE');
    });

    it('encodes the authoritative tenant slug and cannot synthesize a foreign tenant destination', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /Open My account/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/account/profile');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/account/profile');
    });

    it('fails closed when tenant.view or the exact profile API contract is missing', () => {
        const first = renderControl(context('alpha', []));
        expect(screen.queryByRole('link', { name: /Open My account/i })).not.toBeInTheDocument();
        first.unmount();

        renderControl(context('alpha', ['tenant.view'], '/tenants/beta/route-api/account-profile'));
        expect(screen.queryByRole('link', { name: /Open My account/i })).not.toBeInTheDocument();
    });
});
