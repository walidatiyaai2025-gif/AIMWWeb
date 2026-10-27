import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { EMAIL_HISTORY_APPLY_FILTERS_OPERATION_ID, EMAIL_HISTORY_CLEAR_FILTERS_OPERATION_ID } from '../email-history-filters-control';
import { LocaleProvider } from '../i18n';
import { WorkspacePage } from '../pages';

const route: WorkspaceRoute = {
    key: 'email-history',
    path: '/email/history',
    group: 'operations',
    icon: '✉',
    label: { en: 'Email Delivery History', ar: 'سجل إرسال البريد' },
    description: { en: 'Inspect application email delivery history.', ar: 'مراجعة سجل إرسال البريد من التطبيق.' },
    apiKey: 'email-history',
    permission: 'diagnostics.view',
    kind: 'resource',
};

function context(): FrontendContext {
    return {
        user: { id: 1, name: 'Operator', email: 'operator@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'tenant.manage', 'diagnostics.view'],
        connectors: [],
        capabilities: {},
        api: { 'email-history': '/api/v1/tenants/alpha/email/deliveries' },
        actions: {},
    };
}

function renderWorkspace() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(
        <QueryClientProvider client={client}>
            <LocaleProvider>
                <ToastProvider>
                    <WorkspacePage context={context()} route={route} />
                </ToastProvider>
            </LocaleProvider>
        </QueryClientProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe(`${EMAIL_HISTORY_APPLY_FILTERS_OPERATION_ID} ApplyFiltersAsync`, () => {
    it('applies site, status and correlation to the authoritative query and renders refreshed results', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'before', correlation_id: 'old', status: 'QUEUED', template: 'before' }],
                total: 1, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'after', correlation_id: 'trace-1', status: 'SENT', template: 'filtered' }],
                total: 1, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();
        expect(await screen.findByRole('cell', { name: 'QUEUED' })).toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText('Site ID'), { target: { value: '7' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'SENT' } });
        fireEvent.change(screen.getByPlaceholderText('Correlation ID'), { target: { value: ' trace-1 ' } });

        const apply = screen.getByRole('button', { name: 'Apply' });
        expect(apply).toHaveAttribute('data-canonical-operation', EMAIL_HISTORY_APPLY_FILTERS_OPERATION_ID);
        fireEvent.click(apply);

        expect(await screen.findByRole('cell', { name: 'SENT' })).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        const url = String(fetchMock.mock.calls[1]?.[0]);
        expect(url).toContain('/api/v1/tenants/alpha/email/deliveries?page=1');
        expect(url).toContain('site_id=7');
        expect(url).toContain('status=SENT');
        expect(url).toContain('correlation=trace-1');
        expect(EMAIL_HISTORY_APPLY_FILTERS_OPERATION_ID).toBe('AIMW-EMAI-F8E8A2BEE9');
    });


    it('clears Email History filters through the existing shared clear control without rendering a duplicate action', async () => {
        const clearPayload = {
            data: [{ delivery_id: 'all', correlation_id: 'all', status: 'FAILED', template: 'all' }],
            total: 1, current_page: 1, last_page: 1,
        };
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'before', correlation_id: 'old', status: 'QUEUED', template: 'before' }],
                total: 1, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [{ delivery_id: 'filtered', correlation_id: 'trace-1', status: 'SENT', template: 'filtered' }],
                total: 1, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockImplementation(() => Promise.resolve(new Response(JSON.stringify(clearPayload), {
                status: 200, headers: { 'content-type': 'application/json' },
            })));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();
        expect(await screen.findByRole('cell', { name: 'QUEUED' })).toBeInTheDocument();
        fireEvent.change(screen.getByPlaceholderText('Site ID'), { target: { value: '7' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'SENT' } });
        fireEvent.change(screen.getByPlaceholderText('Correlation ID'), { target: { value: 'trace-1' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));
        expect(await screen.findByRole('cell', { name: 'SENT' })).toBeInTheDocument();

        const clearButtons = screen.getAllByRole('button', { name: 'Clear filters' });
        expect(clearButtons).toHaveLength(1);
        expect(clearButtons[0]).toHaveAttribute('data-canonical-operation-alias', EMAIL_HISTORY_CLEAR_FILTERS_OPERATION_ID);
        fireEvent.click(clearButtons[0]);

        expect(await screen.findByRole('cell', { name: 'FAILED' })).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Site ID')).toHaveValue(null);
        expect(screen.getByRole('combobox', { name: 'Status' })).toHaveValue('');
        expect(screen.getByPlaceholderText('Correlation ID')).toHaveValue('');
        await waitFor(() => expect(fetchMock.mock.calls.length).toBeGreaterThanOrEqual(3));
        const url = String(fetchMock.mock.calls.at(-1)?.[0]);
        expect(url).toContain('/api/v1/tenants/alpha/email/deliveries?page=1');
        expect(url).not.toContain('site_id=');
        expect(url).not.toContain('status=');
        expect(url).not.toContain('correlation=');
        expect(EMAIL_HISTORY_CLEAR_FILTERS_OPERATION_ID).toBe('AIMW-EMAI-8C9768BCD0');
    });

    it('renders a real empty result and surfaces backend errors instead of fabricating success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({
                data: [], total: 0, current_page: 1, last_page: 1,
            }), { status: 200, headers: { 'content-type': 'application/json' } }))
            .mockResolvedValueOnce(new Response(JSON.stringify({
                message: 'Email history unavailable.', code: 'email_history_unavailable',
            }), { status: 500, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();
        expect(await screen.findByText('No records were returned.')).toBeInTheDocument();

        fireEvent.change(screen.getByRole('combobox', { name: 'Status' }), { target: { value: 'FAILED' } });
        fireEvent.click(screen.getByRole('button', { name: 'Apply' }));

        expect(await screen.findByText('Data could not be loaded')).toBeInTheDocument();
        expect(screen.getByText('Email history unavailable.')).toBeInTheDocument();
    });
});
