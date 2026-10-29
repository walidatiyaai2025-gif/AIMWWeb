import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    GLOBAL_TAXONOMY_SYNC_OPERATION_ID,
    GlobalTaxonomySyncLinkControl,
} from '../global-taxonomy-sync-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const connector: FrontendContext['connectors'] = [
    { key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] },
];

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view', 'sync.view'],
    api: Record<string, string> = {
        taxonomy: '/tenants/alpha/api/taxonomy',
        sync: '/tenants/alpha/api/sync',
    },
    capabilities: FrontendContext['capabilities'] = {},
    connectors: FrontendContext['connectors'] = connector,
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors,
    capabilities,
    api,
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <GlobalTaxonomySyncLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-SYNC-2C6ADCE34C Taxonomy to Synchronization navigation', () => {
    it('renders the source-equivalent sync destination for the active tenant only', () => {
        renderControl(context());
        const link = screen.getByRole('link', { name: 'Open synchronization' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/sync');
        expect(link).toHaveAttribute('data-canonical-operation', GLOBAL_TAXONOMY_SYNC_OPERATION_ID);
    });

    it('enforces the foreign tenant 404 routing boundary by encoding caller-controlled path segments', () => {
        renderControl(context('alpha/../foreign'));
        const link = screen.getByRole('link', { name: 'Open synchronization' });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/module/sync');
        expect(link.getAttribute('href')).not.toBe('/tenants/foreign/module/sync');
    });

    it.each([
        ['tenant permission', ['content.view', 'sync.view'], {}, connector],
        ['taxonomy permission', ['tenant.view', 'sync.view'], {}, connector],
        ['sync permission', ['tenant.view', 'content.view'], {}, connector],
        ['taxonomy connector', ['tenant.view', 'content.view', 'sync.view'], {}, []],
    ])('fails closed before protected route 403 when %s is absent', (_name, permissions, _unused, connectors) => {
        renderControl(context('alpha', permissions, undefined, {}, connectors));
        expect(screen.queryByRole('link', { name: 'Open synchronization' })).not.toBeInTheDocument();
    });

    it('fails closed when the sync API contract is missing', () => {
        renderControl(context('alpha', undefined, { taxonomy: '/tenants/alpha/api/taxonomy' }));
        expect(screen.queryByRole('link', { name: 'Open synchronization' })).not.toBeInTheDocument();
    });

    it('fails closed if synchronization is disabled by the server', () => {
        renderControl(context('alpha', undefined, undefined, { sync: { state: 'disabled_by_owner' } }));
        expect(screen.queryByRole('link', { name: 'Open synchronization' })).not.toBeInTheDocument();
    });
});
