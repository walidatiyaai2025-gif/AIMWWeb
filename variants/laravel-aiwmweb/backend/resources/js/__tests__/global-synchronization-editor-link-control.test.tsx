import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    GlobalSynchronizationEditorLinkControl,
    GLOBAL_SYNCHRONIZATION_EDITOR_LINK_OPERATION_ID,
    globalSyncEditorDestination,
} from '../global-synchronization-editor-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha', permissions = ['tenant.view', 'content.view']): FrontendContext => ({
    user: { id: 10, name: 'Tenant member', email: 'member@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const remoteUpdate = { kind: 'RemoteUpdated' as const, content_type: 'post' as const, wordpress_id: 42 };
const removed = { kind: 'RemoteDeleted' as const, content_type: 'post' as const, wordpress_id: 42 };

function renderEditor(value: FrontendContext = context(), siteId = 7, conflict: { kind: 'RemoteUpdated' | 'RemoteDeleted'; content_type: 'post' | 'page'; wordpress_id: number } = remoteUpdate) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <GlobalSynchronizationEditorLinkControl context={value} siteId={siteId} conflict={conflict} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-SYNC-0239B0E6A3 conflict editor parity', () => {
    it('routes a confirmed remote update to its authoritative tenant/site/content editor', () => {
        renderEditor();
        const link = screen.getByRole('link', { name: 'Open editor to merge' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/sites/7/content/post/42/edit');
        expect(link).toHaveAttribute('data-canonical-operation', GLOBAL_SYNCHRONIZATION_EDITOR_LINK_OPERATION_ID);
    });

    it('supports page conflicts without changing their content type', () => {
        const destination = globalSyncEditorDestination('alpha', 7, {
            kind: 'RemoteUpdated', content_type: 'page', wordpress_id: 13,
        });
        expect(destination).toBe('/tenants/alpha/sites/7/content/page/13/edit');
    });

    it('does not offer an editor for remote deletions', () => {
        renderEditor(context(), 7, removed);
        expect(screen.queryByRole('link', { name: 'Open editor to merge' })).not.toBeInTheDocument();
    });

    it('fails closed for invalid or foreign site targets before tenant 404 routing', () => {
        expect(globalSyncEditorDestination('alpha', 0, remoteUpdate)).toBeNull();
        expect(globalSyncEditorDestination('alpha', '7/../../other', remoteUpdate)).toBeNull();
        expect(globalSyncEditorDestination('alpha', 7, { ...remoteUpdate, wordpress_id: -1 })).toBeNull();
        expect(globalSyncEditorDestination('alpha', 7, { ...remoteUpdate, wordpress_id: 2.1 })).toBeNull();
        expect(globalSyncEditorDestination('alpha/../other', 7, remoteUpdate)).toBeNull();
    });

    it('does not expose editor access without both view permissions', () => {
        renderEditor(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: 'Open editor to merge' })).not.toBeInTheDocument();
    });

    it('encodes valid tenant labels to keep editor navigation tenant-bound', () => {
        renderEditor(context('tenant space'));
        expect(screen.getByRole('link', { name: 'Open editor to merge' }))
            .toHaveAttribute('href', '/tenants/tenant%20space/sites/7/content/post/42/edit');
    });
});
