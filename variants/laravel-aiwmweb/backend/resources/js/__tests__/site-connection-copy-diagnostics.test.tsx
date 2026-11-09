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
        expect(report).toContain('WordPress: unavailable (not_probed)');
        expect(report).toContain('Language: unavailable (not_probed)');
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

    it('never calls stored-only WordPress and language metadata remotely verified (#1758)', () => {
        const site = {
            id: 7, name: 'Local metadata', url: 'https://wp.test',
            connection_status: 'connected', wordpress_version: '6.7', language_code: 'en_US',
        };
        const stored = formatSiteDiagnosticsReport({
            site, connection: 'CONNECTED',
            metadata: {
                wordpress_version: { state: 'stored_unverified', checked_at: null },
                language_code: { state: 'stored_unverified', checked_at: null },
            },
        }, []);
        expect(stored).toContain('WordPress: 6.7 (stored; not remotely verified)');
        expect(stored).toContain('Language: en_US (stored; not remotely verified)');
        expect(stored).toContain('WordPress metadata checked: not verified');
        expect(stored).not.toContain('6.7 (verified by Connector)');

        const confirmed = formatSiteDiagnosticsReport({
            site, connection: 'CONNECTED',
            metadata: {
                wordpress_version: { state: 'verified', checked_at: '2026-11-08T13:15:00Z' },
                language_code: { state: 'verified', checked_at: '2026-11-08T13:15:00Z' },
            },
        }, []);
        expect(confirmed).toContain('WordPress: 6.7 (verified by Connector)');
        expect(confirmed).toContain('Language: en_US (verified by Connector)');

        const legacy = formatSiteDiagnosticsReport({ site, connection: 'CONNECTED' }, []);
        expect(legacy).toContain('WordPress: 6.7 (verification not established: not_probed)');
        expect(legacy).not.toContain('verified by Connector');

        const untrusted = formatSiteDiagnosticsReport({
            site, connection: 'CONNECTED',
            metadata: {
                wordpress_version: { state: 'verified password=NEVER_LEAK', checked_at: null },
                language_code: { state: 'stored_unverified<script>', checked_at: null },
            },
        }, []);
        expect(untrusted).toContain('WordPress: 6.7 (verification not established: not_probed)');
        expect(untrusted).not.toContain('NEVER_LEAK');
        expect(untrusted).not.toContain('<script>');
    });

    it('never copies credentialed site URLs, raw upstream failures, or untrusted report fields', () => {
        const report = formatSiteDiagnosticsReport({
            site: {
                id: 7,
                name: 'Alpha Test',
                url: 'https://operator:private-pass@wp.example.test/hidden-path?token=query-secret#fragment-secret',
                connection_status: 'CONNECTED',
                wordpress_version: '6.8',
                language_code: 'en_US',
            },
            connection: 'CONNECTED',
            metadata: {
                wordpress_version: { state: 'verified', checked_at: '2026-11-08T13:15:00Z' },
                language_code: { state: 'verified', checked_at: '2026-11-08T13:15:00Z' },
            },
            last_verified_at: '2026-11-08T13:16:00Z',
        }, [{
            operation: 'synchronization',
            status: 'failed',
            message: 'Bearer private-bearer https://unsafe.example/?api_key=hidden {"password":"private-json"} SQL: delete from users',
            started_at: '2026-11-08T13:17:00Z',
            details: {
                sync_run_id: 7,
                failure_category: 'authentication',
                failure_code: 'WP_SYNC_AUTHENTICATION',
                failure_message: 'raw private diagnostic message must not leave the tenant',
            },
        }]);
        expect(report).toContain('URL: https://wp.example.test');
        expect(report).toContain('WordPress: 6.8');
        expect(report).toContain('Language: en_US');
        expect(report).toContain('WP_SYNC_AUTHENTICATION');
        expect(report).toContain('Check WordPress authentication and Connector permissions.');
        for (const secret of ['operator', 'private-pass', 'hidden-path', 'query-secret', 'fragment-secret',
            'private-bearer', 'unsafe.example', 'private-json', 'delete from users', 'raw private diagnostic']) {
            expect(report).not.toContain(secret);
        }
    });

    it('withholds invalid URL, control-character injection and unsupported failure details instead of copying them', () => {
        const report = formatSiteDiagnosticsReport({
            site: {
                id: 7,
                name: 'Alpha' + '\n' + 'password=private-secret',
                url: 'javascript:alert(1)',
                connection_status: 'CONNECTED' + '\n' + 'token=private-secret',
                wordpress_version: '6.9<script>',
                language_code: 'en_US?token=private-secret',
            },
            connection: 'CONNECTED',
            metadata: {
                wordpress_version: { state: 'Bearer private-secret', checked_at: '2026-11-08T00:00:00Z' + '\n' + 'password=private-secret' },
                language_code: { state: 'not_supported', checked_at: null },
            },
        }, [{
            operation: 'sync' + '\n' + 'password=private-secret',
            status: 'failed',
            message: 'WordPress token=private-secret',
            details: { failure_category: 'token=private-secret', failure_code: 'WP_SYNC_private-secret' },
            started_at: 'not a date password=private-secret',
        }]);
        expect(report).toContain('Site: [WITHHELD]');
        expect(report).toContain('URL: unavailable');
        expect(report).toContain('Status: unknown');
        expect(report).toContain('WordPress: unavailable (not_probed)');
        expect(report).toContain('Language: unavailable (not_supported)');
        expect(report).toContain('operation_withheld FAILED - Failed; review authorized operation details.');
        expect(report).not.toContain('private-secret');
        expect(report).not.toContain('<script>');
        expect(report).not.toContain('javascript:');
    });

    it('copy button passes only redacted origin and static failure guidance to clipboard', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                site: { id: 7, name: 'Alpha Site',
                    url: 'https://user:password@wp.example.test/wp?api_key=private-token', connection_status: 'connected' },
                connection: 'CONNECTED',
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                items: [{ operation: 'synchronization', status: 'failed',
                    message: 'Bearer private-bearer from upstream exception', started_at: '2026-11-08T12:00:00Z',
                    details: { failure_category: 'transport', failure_code: 'WP_SYNC_TRANSPORT' } }],
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        const writeText = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('fetch', fetchMock);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        render(<LocaleProvider><MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}>
            <Routes><Route path="/tenants/:tenantSlug/sites/:siteId/connection"
                element={<SiteConnectionCenterSyncControl context={context()} />} /></Routes>
        </MemoryRouter></LocaleProvider>);
        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Copy diagnostics' }));
        await waitFor(() => expect(writeText).toHaveBeenCalledTimes(1));
        const copied = String(writeText.mock.calls[0]?.[0]);
        expect(copied).toContain('URL: https://wp.example.test');
        expect(copied).toContain('WP_SYNC_TRANSPORT');
        expect(copied).not.toContain('password');
        expect(copied).not.toContain('private-token');
        expect(copied).not.toContain('private-bearer');
    });

});
