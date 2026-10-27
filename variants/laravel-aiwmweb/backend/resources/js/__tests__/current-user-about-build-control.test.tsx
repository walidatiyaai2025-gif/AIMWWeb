import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    CURRENT_USER_ABOUT_BUILD_OPERATION_ID,
    CurrentUserAboutBuildControl,
} from '../current-user-about-build-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view'],
    tenants: Array<{ slug: string; name: string }> = [{ slug, name: 'Alpha' }],
    userId = 10,
): FrontendContext => ({
    user: { id: userId, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants,
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <LocaleProvider>
            <CurrentUserAboutBuildControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-IDEN-2387758315 current-user About Build control', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="user-chip"></div>';
        window.history.replaceState({}, '', '/tenants/beta/module/posts?tenant=beta');
    });

    afterEach(() => {
        cleanup();
        document.body.innerHTML = '';
        window.history.replaceState({}, '', '/');
    });

    it('binds the exact canonical operation to the authoritative active-tenant About Build route', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Open About Build/i });
        expect(CURRENT_USER_ABOUT_BUILD_OPERATION_ID).toBe('AIMW-IDEN-2387758315');
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_ABOUT_BUILD_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha/about-build');
        expect(document.querySelectorAll(`[data-canonical-operation="${CURRENT_USER_ABOUT_BUILD_OPERATION_ID}"]`)).toHaveLength(1);
    });

    it('derives and encodes the destination only from trusted server context, never browser path or query input', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /Open About Build/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/about-build');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/about-build');
        expect(link.getAttribute('href')).not.toContain('?tenant=beta');
    });

    it.each([
        [[], [{ slug: 'alpha', name: 'Alpha' }], 10],
        [['tenant.view'], [{ slug: 'beta', name: 'Beta' }], 10],
        [['tenant.view'], [{ slug: 'alpha', name: 'Alpha' }], 0],
    ])('fails closed when permission, active membership, or server-derived identity is incomplete', (permissions, tenants, userId) => {
        renderControl(context('alpha', permissions as string[], tenants as Array<{ slug: string; name: string }>, userId as number));
        expect(screen.queryByRole('link', { name: /Open About Build/i })).not.toBeInTheDocument();
    });

    it('accepts the authoritative wildcard permission without introducing a caller-supplied tenant selector', () => {
        renderControl(context('alpha', ['*']));
        const link = screen.getByRole('link', { name: /Open About Build/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/about-build');
    });
});
