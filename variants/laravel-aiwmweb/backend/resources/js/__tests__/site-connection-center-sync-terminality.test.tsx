import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    SITE_CONNECTION_CENTER_SYNC_OPERATION_ID,
    SiteConnectionCenterSyncControl,
    connectionCenterSyncOutcome,
    formatSyncHealthState,
    safeSyncHistoryFailure,
    siteConnectionCenterEndpoints,
} from '../site-connection-center-sync-control';
import type { FrontendContext } from '../core';

function context(permissions = ['tenant.view', 'sites.view', 'sites.manage']): FrontendContext {
    return {
        user: { id: 7, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions,
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
    };
}

function renderControl(active = context()) {
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={['/tenants/alpha/sites/7/connection']}>
                <Routes>
                    <Route
                        path="/tenants/:tenantSlug/sites/:siteId/connection"
                        element={<SiteConnectionCenterSyncControl context={active} />}
                    />
                </Routes>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Site Diagnostics truthful synchronization history states', () => {
    it.each([
        ['queued', 'Queued', 'في قائمة الانتظار'],
        ['in_progress', 'In progress', 'جارٍ تنفيذ المزامنة'],
        ['cancelled', 'Cancelled', 'أُلغيت المزامنة'],
        ['failed', 'Failed — review operation history', 'فاشلة — راجع سجل العمليات'],
        ['succeeded', 'Succeeded', 'ناجحة'],
        ['not_probed', 'Not yet verified', 'لم يتم التحقق'],
        ['unknown', 'Synchronization status unknown', 'حالة المزامنة غير معروفة'],
        ['Bearer secret-status', 'Synchronization status unknown', 'حالة المزامنة غير معروفة'],
    ])('does not fabricate failure for %s', (state, english, arabic) => {
        expect(formatSyncHealthState(state, 'en')).toBe(english);
        expect(formatSyncHealthState(state, 'ar')).toBe(arabic);
    });

    it('keeps queued synchronization distinct from an existing CONNECTED Connector in the actual workspace', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const payload = String(input).includes('/operations')
                ? { items: [], page: 1, per_page: 25, total: 0, last_page: 1 }
                : {
                    site: { id: 7, name: 'Alpha Site', connection_status: 'CONNECTED' },
                    connection: 'CONNECTED',
                    sync_health: { state: 'queued', last_attempt_at: '2026-11-08T10:00:00Z' },
                };
            return new Response(JSON.stringify(payload), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view']));
        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(screen.getByTestId('sync-health-state')).toHaveTextContent('Queued');
        expect(screen.getByText(/Connection: CONNECTED/)).toBeInTheDocument();
        expect(screen.getByText(/Last sync attempt/)).toHaveTextContent('2026-11-08T10:00:00Z');
    });
});

describe('AIMW-BILL-3762C05261 Site Connection Center SynchronizeAsync', () => {
    it('runs one idempotent sync and rereads authoritative connection plus operation history', async () => {
        const initialConnection = {
            site: { id: 7, name: 'Alpha Site', connection_status: 'connected' },
            connection: 'CONNECTED',
            last_verified_at: null,
        };
        const rereadConnection = {
            ...initialConnection,
            last_verified_at: '2026-10-07T11:00:00Z',
        };
        const initialOperations = {
            items: [{
                id: 10,
                operation: 'diagnostic_recheck',
                status: 'succeeded',
                message: 'Connection checked.',
                affected_records: null,
                started_at: '2026-10-07T10:00:00Z',
            }],
        };
        const rereadOperations = {
            items: [{
                id: 11,
                operation: 'synchronization',
                status: 'succeeded',
                message: 'Synchronization completed.',
                affected_records: 4,
                started_at: '2026-10-07T11:00:00Z',
            }, ...initialOperations.items],
        };

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify(initialConnection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(initialOperations), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ id: 41, status: 'succeeded', processed: 4 }), { status: 202, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(rereadConnection), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify(rereadOperations), { status: 200, headers: { 'content-type': 'application/json' } }));

        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('crypto', { randomUUID: () => '22222222-2222-4222-8222-222222222222' });

        renderControl();

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(await screen.findByText('diagnostic_recheck')).toBeInTheDocument();

        const button = screen.getByRole('button', { name: 'Sync now' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_CONNECTION_CENTER_SYNC_OPERATION_ID);
        fireEvent.click(button);

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(5));

        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/tenants/alpha/sites/7/sync');
        const init = fetchMock.mock.calls[2]?.[1] as RequestInit;
        expect(init.method).toBe('POST');
        expect(new Headers(init.headers).get('Idempotency-Key')).toBe('22222222-2222-4222-8222-222222222222');
        expect(init.body).toBe('{}');

        expect(await screen.findByRole('status')).toHaveTextContent('Synchronization completed');
        expect(await screen.findByText('synchronization')).toBeInTheDocument();
        expect(screen.getByText('Affected records: 4')).toBeInTheDocument();
    });

    it('keeps failed synchronization visible while connector is connected', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const payload = path.includes('/operations')
                ? { items: [], page: 1, per_page: 25, total: 0, last_page: 1 }
                : { site: { id: 7, name: 'Alpha Site', connection_status: 'CONNECTED' },
                    connection: 'CONNECTED',
                    sync_health: { state: 'failed', last_attempt_at: '2026-11-08T10:00:00Z' } };
            return new Response(JSON.stringify(payload), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view']));
        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(screen.getByTestId('sync-health-state')).toHaveTextContent('Failed — review operation history');
        expect(screen.getByText(/Last sync attempt/)).toHaveTextContent('2026-11-08T10:00:00Z');
        expect(screen.getByText(/Connection: CONNECTED/)).toBeInTheDocument();
    });

    it('shows bounded safe sync failure details with authorized operation drilldown', async () => {
        const correlationId = '12345678-1234-4234-8234-1234567890ab';
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const payload = path.includes('/operations')
                ? { items: [{
                    id: 44, correlation_id: correlationId,
                    operation: 'synchronization', status: 'failed',
                    message: 'WordPress synchronization failed.',
                    details: {
                        sync_run_id: 91,
                        failure_code: 'WP_SYNC_AUTHENTICATION',
                        failure_category: 'authentication',
                        failure_message: 'WordPress rejected the connection. Recheck Connector credentials and signed request permissions.',
                    },
                }] }
                : { site: { id: 7, name: 'Alpha Site', connection_status: 'connected' }, connection: 'CONNECTED' };
            return new Response(JSON.stringify(payload), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view', 'execution.view']));

        expect(await screen.findByText(/WP_SYNC_AUTHENTICATION/)).toBeInTheDocument();
        expect(screen.getByText(/Check WordPress authentication and Connector permissions/)).toBeInTheDocument();
        expect(screen.getByText(/Synchronization run/)).toHaveTextContent('#91');
        const details = screen.getByRole('link', { name: 'Operation details' });
        expect(details).toHaveAttribute('href', `/tenants/alpha/site-operations/${correlationId}`);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(screen.queryByText(/private-value|unsafe-token/)).not.toBeInTheDocument();
    });

    it('never renders legacy raw synchronization errors or forged codes', async () => {
        const forged = {
            id: 52, operation: 'synchronization', status: 'failed',
            message: 'Bearer private-token password=private-password',
            details: {
                failure_category: 'authentication',
                failure_code: 'WP_SYNC_AUTHENTICATION; private-code',
                failure_message: 'https://private.example.test/?token=private-query',
            },
        };
        expect(safeSyncHistoryFailure(forged)).toBeNull();
        expect(safeSyncHistoryFailure({
            ...forged,
            details: { ...forged.details, failure_code: 'WP_SYNC_AUTHENTICATION' },
        })).toEqual({
            code: 'WP_SYNC_AUTHENTICATION',
            en: 'Check WordPress authentication and Connector permissions.',
            ar: 'فشل التحقق من اتصال WordPress. راجع بيانات الربط وتوقيع الطلبات.',
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const payload = String(input).includes('/operations')
                ? { items: [forged] }
                : { site: { id: 7, name: 'Alpha Site', connection_status: 'CONNECTED' }, connection: 'CONNECTED' };
            return new Response(JSON.stringify(payload), { status: 200, headers: { 'content-type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view']));
        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(screen.getByText('Synchronization failed. Review the safe classification below.')).toBeInTheDocument();
        expect(screen.queryByText(/private-token|private-password|private-code|private-query/)).not.toBeInTheDocument();
        expect(screen.queryByText(/Failure code:/)).not.toBeInTheDocument();
    });

    it('fails closed for invalid identifiers and hides mutation without sites.manage', async () => {
        expect(siteConnectionCenterEndpoints('alpha', '../7')).toBeNull();
        expect(siteConnectionCenterEndpoints('alpha/beta', 7)).toBeNull();
        expect(connectionCenterSyncOutcome('cancelled')).toBe('failure');
        expect(connectionCenterSyncOutcome('cancel_requested')).toBe('failure');
        expect(SITE_CONNECTION_CENTER_SYNC_OPERATION_ID).toBe('AIMW-BILL-3762C05261');

        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                site: { id: 7, name: 'Alpha Site', connection_status: 'connected' },
                connection: 'CONNECTED',
                last_verified_at: null,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ items: [] }), { status: 200, headers: { 'content-type': 'application/json' } }));

        vi.stubGlobal('fetch', fetchMock);
        renderControl(context(['tenant.view', 'sites.view']));

        expect(await screen.findByText('Alpha Site')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sync now' })).not.toBeInTheDocument();
    });
});
