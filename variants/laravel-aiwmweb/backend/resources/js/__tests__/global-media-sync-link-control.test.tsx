import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    canOpenGlobalMediaSync,
    GLOBAL_MEDIA_OPEN_SYNC_OPERATION_ID,
    GlobalMediaSyncLinkControl,
} from '../global-media-sync-link-control';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha Owner', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view', 'sync.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['media.read'] }],
        capabilities: {},
        api: { media: '/api/v1/tenants/alpha/sites/7/media', sync: '/api/v1/tenants/alpha/sync' },
        actions: {},
        ...overrides,
    };
}

function renderControl(value = context(), mediaAvailable = true) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <GlobalMediaSyncLinkControl context={value} mediaAvailable={mediaAvailable} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-SYNC-2A256826D2 GlobalMediaExplorer Open synchronization link', () => {
    it('navigates to the real tenant-scoped sync workspace without triggering mutation', () => {
        renderControl();
        const link = screen.getByRole('link', { name: 'Open synchronization' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/sync');
        expect(link).toHaveAttribute('data-canonical-operation', GLOBAL_MEDIA_OPEN_SYNC_OPERATION_ID);
        expect(GLOBAL_MEDIA_OPEN_SYNC_OPERATION_ID).toBe('AIMW-SYNC-2A256826D2');
    });

    it('encodes the active tenant rather than accepting a forged global destination', () => {
        renderControl(context({ tenant: { slug: 'alpha/../beta', name: 'Alpha' } }));
        expect(screen.getByRole('link', { name: 'Open synchronization' }))
            .toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/module/sync');
    });

    it.each([
        ['tenant permission', ['content.view', 'sync.view']],
        ['media view', ['tenant.view', 'sync.view']],
        ['sync view', ['tenant.view', 'content.view']],
    ])('does not offer protected navigation without %s', (_reason, permissions) => {
        renderControl(context({ permissions }));
        expect(screen.queryByRole('link', { name: 'Open synchronization' })).not.toBeInTheDocument();
    });

    it('fails closed if media endpoint, connected media scope, or sync capability is missing', () => {
        const noMedia = context();
        expect(canOpenGlobalMediaSync(noMedia, false)).toBe(false);
        expect(canOpenGlobalMediaSync(context({ connectors: [] }), true)).toBe(false);
        expect(canOpenGlobalMediaSync(context({ capabilities: { sync: { state: 'disabled_by_owner' } } }), true)).toBe(false);

        renderControl(context({ capabilities: { sync: { state: 'disabled_by_owner' } } }));
        expect(screen.queryByRole('link', { name: 'Open synchronization' })).not.toBeInTheDocument();
    });
});
