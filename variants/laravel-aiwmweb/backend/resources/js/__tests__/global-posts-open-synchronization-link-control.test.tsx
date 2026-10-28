import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import {
    GLOBAL_POSTS_OPEN_SYNCHRONIZATION_OPERATION_ID,
    GlobalPostsOpenSynchronizationLinkControl,
    globalPostsSynchronizationDestination,
} from '../global-posts-open-synchronization-link-control';

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view', 'sync.view'],
    connectors: FrontendContext['connectors'] = [
        { key: 'wordpress', state: 'connected', scopes: ['posts.read'] },
    ],
): FrontendContext => ({
    user: { id: 4, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors,
    capabilities: {},
    api: {},
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <GlobalPostsOpenSynchronizationLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

afterEach(() => { cleanup(); window.localStorage.clear(); });

describe('AIMW-SYNC-EEFDDFFF87 Global Posts open synchronization navigation', () => {
    it('renders only the existing guarded synchronization destination', () => {
        renderControl(context());
        const link = screen.getByRole('link', { name: 'Open synchronization' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/sync');
        expect(link.closest('nav')).toHaveAttribute(
            'data-canonical-operation', GLOBAL_POSTS_OPEN_SYNCHRONIZATION_OPERATION_ID,
        );
    });

    it.each([
        ['tenant.view', ['content.view', 'sync.view']],
        ['content.view', ['tenant.view', 'sync.view']],
        ['sync.view', ['tenant.view', 'content.view']],
    ])('fails closed before destination authorization returns 403 when %s is missing', (_name, permissions) => {
        renderControl(context('alpha', permissions));
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('fails closed for missing WordPress posts read capability', () => {
        renderControl(context('alpha', undefined, []));
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('honors server capability disablement and never presents a fictitious action', () => {
        const c = context();
        c.capabilities = { sync: { state: 'disabled_by_owner' } };
        renderControl(c);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('encodes the tenant slug to preserve the foreign-tenant 404 boundary', () => {
        const c = context('alpha/../foreign');
        expect(globalPostsSynchronizationDestination(c)).toBe('/tenants/alpha%2F..%2Fforeign/module/sync');
        renderControl(c);
        expect(screen.getByRole('link', { name: 'Open synchronization' }))
            .not.toHaveAttribute('href', '/tenants/foreign/module/sync');
    });

    it('keeps the Arabic source label on the same real destination', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        renderControl(context());
        expect(screen.getByRole('link', { name: 'فتح المزامنة' }))
            .toHaveAttribute('href', '/tenants/alpha/module/sync');
    });
});
