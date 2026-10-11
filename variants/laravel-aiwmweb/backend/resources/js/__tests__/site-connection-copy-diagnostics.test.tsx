import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_CENTER_COPY_DIAGNOSTICS_OPERATION_ID,
    SiteConnectionCenterSyncControl,
    formatSiteDiagnosticsReport,
} from '../site-connection-center-sync-control';

function context(): FrontendContext {
    return {
        user: { id: 7, name: 'Reader', email: 'reader@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-D4E1DA62D9 CopyDiagnosticsAsync', () => {
    it('copies an authoritative redacted site report for read-only operators', async () => {
        const connection = {
            site: {
                id: 7,
                name: 'Alpha Site',
                url: 'https://alpha.test',
                connection_status: 'connected',
                wordpress_version: '6.8',
                language_code: 'en_US',
            },
            connection: 'CONNECTED',
            last_verified_at: '2026-10-11T18:00:00Z',
        };
        const operations = {
            items: [{
                id: 11,
                operation: 'synchronization',
                status: 'succeeded',
                message: 'Synchronization completed.',
                affected_records: 4,
                started_at: '2026-10-11T18:01:00Z',
            }],
        };

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(connection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(operations), { status: 200, headers: { 'content-type': 'application/json' } }));
        const writeText = vi.fn().mockResolvedValue(undefined);

        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        render(
            <LocaleProvider>
                <MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}>
                    <Routes>
                        <Route
                            path="/tenants/:tenantSlug/sites/:siteId/connection"
                            element={<SiteConnectionCenterSyncControl context={context()} />}
                        />
                    </Routes>
                </MemoryRouter>
            </LocaleProvider>,
        );

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        const button = screen.getByRole('button', { name: 'Copy diagnostics' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_CONNECTION_CENTER_COPY_DIAGNOSTICS_OPERATION_ID);
        expect(SITE_CONNECTION_CENTER_COPY_DIAGNOSTICS_OPERATION_ID).toBe('AIMW-BILL-D4E1DA62D9');

        fireEvent.click(button);

        await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
        const report = String(writeText.mock.calls[0]?.[0]);
        expect(report).toContain('AI WordPress Manager - Site Diagnostics');
        expect(report).toContain('Site: Alpha Site');
        expect(report).toContain('URL: https://alpha.test');
        expect(report).toContain('WordPress: 6.8');
        expect(report).toContain('[2026-10-11T18:01:00Z] synchronization OK - Synchronization completed.');
        expect(report).not.toContain('password');
        expect(report).not.toContain('token');
        expect(await screen.findByRole('status')).toHaveTextContent('Diagnostic report copied.');
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
    });

    it('formats at most the twenty most recent operations and reports clipboard failure', async () => {
        const connection = {
            site: { id: 7, name: 'Alpha Site', connection_status: 'connected' },
            connection: 'CONNECTED',
            last_verified_at: null,
        };
        const operations = Array.from({ length: 25 }, (_, index) => ({
            id: index + 1,
            operation: `op-${index + 1}`,
            status: 'succeeded',
            message: `message-${index + 1}`,
            started_at: `2026-10-11T18:${String(index).padStart(2, '0')}:00Z`,
        }));
        const report = formatSiteDiagnosticsReport(connection, operations);
        expect(report).toContain('op-20');
        expect(report).not.toContain('op-21');

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(connection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'content-type': 'application/json' } }));

        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });

        render(
            <LocaleProvider>
                <MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}>
                    <Routes>
                        <Route
                            path="/tenants/:tenantSlug/sites/:siteId/connection"
                            element={<SiteConnectionCenterSyncControl context={context()} />}
                        />
                    </Routes>
                </MemoryRouter>
            </LocaleProvider>,
        );

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Copy diagnostics' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Clipboard API is unavailable');
    });
});
