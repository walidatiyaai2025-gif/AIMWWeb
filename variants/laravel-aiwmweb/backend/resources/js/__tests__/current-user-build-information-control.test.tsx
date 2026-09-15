import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    CURRENT_USER_BUILD_INFORMATION_OPERATION,
    CurrentUserBuildInformationControl,
} from '../current-user-build-information-control';
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
            <CurrentUserBuildInformationControl context={value} />
        </LocaleProvider>,
    );
}

describe('AIMW-IDEN-2387758315 current-user Build information control', () => {
    beforeEach(() => {
        window.localStorage.clear();
        document.body.innerHTML = '<div class="topbar-actions"></div>';
    });

    afterEach(() => {
        cleanup();
        window.localStorage.clear();
        document.body.innerHTML = '';
    });

    it('binds the exact canonical operation to the active-tenant About Build route', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: 'Open Build information' });
        expect(CURRENT_USER_BUILD_INFORMATION_OPERATION).toBe('AIMW-IDEN-2387758315');
        expect(link).toHaveAttribute('data-canonical-operation', CURRENT_USER_BUILD_INFORMATION_OPERATION);
        expect(link).toHaveAttribute('href', '/tenants/alpha/about-build');
        expect(link).toHaveAttribute('title', 'Branch, version and build details');
        expect(link).toHaveTextContent('Build information');
        expect(document.querySelectorAll(`[data-canonical-operation="${CURRENT_USER_BUILD_INFORMATION_OPERATION}"]`)).toHaveLength(1);
    });

    it('derives and encodes the destination only from trusted server context, never browser pathname', () => {
        window.history.replaceState({}, '', '/tenants/beta/settings');
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: 'Open Build information' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/about-build');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/about-build');
    });

    it('fails closed when tenant.view is absent', () => {
        renderControl(context('alpha', []));

        expect(screen.queryByRole('link', { name: 'Open Build information' })).not.toBeInTheDocument();
    });

    it('preserves the Arabic source label and build-detail description', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        renderControl(context());

        const link = screen.getByRole('link', { name: 'فتح معلومات الإصدار' });
        expect(link).toHaveTextContent('معلومات الإصدار');
        expect(link).toHaveAttribute('title', 'الفرع والنسخة وتفاصيل البناء');
        expect(link).toHaveAttribute('href', '/tenants/alpha/about-build');
    });
});
