import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const OPERATION_ID = 'AIMW-BILL-07A0F6427B';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Backup Admin', email: 'backup-admin@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['backups.view'],
        connectors: [],
        capabilities: {},
        api: { backups: '/tenants/alpha/admin/backups' },
        actions: {},
        ...overrides,
    };
}

const route: WorkspaceRoute = {
    key: 'backups',
    path: '/module/backups',
    group: 'system',
    icon: '⬡',
    label: { en: 'Backup & Restore', ar: 'النسخ الاحتياطي والاستعادة' },
    description: { en: 'Protect and restore tenant-scoped application data.', ar: 'حماية واستعادة بيانات التطبيق الخاصة بالحساب.' },
    apiKey: 'backups',
    permission: 'backups.view',
    controls: ['backups.create', 'backups.restore'],
    kind: 'resource',
};

function renderWorkspace(current = context()) {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={current} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${OPERATION_ID} Reload backups`, () => {
    it('rereads the existing tenant-derived authoritative backup endpoint with GET only', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify([{ id: 11, status: 'completed' }]), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify([{ id: 12, status: 'completed' }]), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('11')).toBeInTheDocument();
        const reload = screen.getByRole('button', { name: 'Reload backups' });
        expect(reload).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/tenants/alpha/admin/backups?page=1');

        fireEvent.click(reload);

        expect(await screen.findByText('12')).toBeInTheDocument();
        expect(screen.queryByText('11')).not.toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][0]).toBe('/tenants/alpha/admin/backups?page=1');
        expect(fetchMock.mock.calls.every(([, options]) => !options || options.method === undefined || options.method === 'GET')).toBe(true);
    });

    it('renders fail-closed when the backup workspace permission is absent', async () => {
        vi.stubGlobal('fetch', vi.fn());
        renderWorkspace(context({ permissions: [] }));
        expect(await screen.findByText('Permission required')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Reload backups' })).not.toBeInTheDocument();
    });
});
