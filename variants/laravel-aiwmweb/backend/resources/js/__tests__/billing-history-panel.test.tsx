import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BillingHistoryPanel, billingHistoryUrl, formatBillingMinorAmount } from '../billing-history-panel';
import { apiRequest, type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

vi.mock('../core', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../core')>()),
    apiRequest: vi.fn(),
}));

const apiMock = vi.mocked(apiRequest);
const context: FrontendContext = {
    user: { id: 3, name: 'Reader', email: 'reader@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'billing.view'],
    connectors: [], capabilities: {}, api: {}, actions: {},
};

function payload(auditPage = 1, transactionPage = 1) {
    return {
        data: {
            audit: [{ id: auditPage, action: `real-audit-${auditPage}`, occurred_at: '2026-11-08T12:00:00Z' }],
            transactions: [{ id: transactionPage, type: `real-transaction-${transactionPage}`, status: 'paid',
                amount_minor: 145, currency: 'KWD', occurred_at: '2026-11-08T12:00:00Z' }],
        },
        pagination: {
            audit: { page: auditPage, last_page: 2, per_page: 25, total: 26, from: auditPage, to: auditPage },
            transactions: { page: transactionPage, last_page: 2, per_page: 25, total: 26,
                from: transactionPage, to: transactionPage },
        },
    };
}

afterEach(() => {
    cleanup();
    apiMock.mockReset();
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('Billing History #1703 real server paging', () => {
    it('encodes tenant and independent page URLs without silently fabricating rows', () => {
        expect(billingHistoryUrl('my tenant', 2, 1, 25))
            .toBe('/api/v1/tenants/my%20tenant/billing/history/paged?audit_page=2&transaction_page=1&per_page=25');
    });

    it('formats currency minor units using correct ISO-4217 precision', () => {
        expect(formatBillingMinorAmount(145, 'KWD', 'en')).toContain('0.145');
        expect(formatBillingMinorAmount(145, 'USD', 'en')).toContain('1.45');
        expect(formatBillingMinorAmount(145, 'JPY', 'en')).toContain('145');
        expect(formatBillingMinorAmount(null, 'KWD', 'en')).toBe('—');
        expect(formatBillingMinorAmount(145, 'BAD_SECRET', 'en')).toBe('—');
    });

    it('uses independent authoritative audit/transaction pages and real refresh', async () => {
        apiMock.mockImplementation((url) => {
            const query = new URL(url, 'https://example.test').searchParams;
            return Promise.resolve(payload(Number(query.get('audit_page')), Number(query.get('transaction_page'))));
        });

        render(<LocaleProvider><BillingHistoryPanel context={context} /></LocaleProvider>);
        expect(await screen.findByText('real-audit-1')).toBeInTheDocument();
        expect(screen.getByText('real-transaction-1')).toBeInTheDocument();

        const audit = within(screen.getByTestId('billing-history-audit'));
        fireEvent.click(audit.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('real-audit-2')).toBeInTheDocument();
        expect(screen.getByText('real-transaction-1')).toBeInTheDocument();

        const transactions = within(screen.getByTestId('billing-history-transactions'));
        fireEvent.click(transactions.getByRole('button', { name: 'Next' }));
        expect(await screen.findByText('real-transaction-2')).toBeInTheDocument();
        expect(screen.getByText('real-audit-2')).toBeInTheDocument();

        expect(apiMock.mock.calls.map((call) => call[0])).toContain(
            '/api/v1/tenants/alpha/billing/history/paged?audit_page=2&transaction_page=1&per_page=25'
        );
        expect(apiMock.mock.calls.map((call) => call[0])).toContain(
            '/api/v1/tenants/alpha/billing/history/paged?audit_page=2&transaction_page=2&per_page=25'
        );
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        await waitFor(() => expect(apiMock).toHaveBeenCalledTimes(4));
    });

    it('keeps errors separate from genuine empty states and provides retry', async () => {
        const empty = { data: { audit: [], transactions: [] },
            pagination: {
                audit: { page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
                transactions: { page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null },
            } };
        apiMock.mockRejectedValueOnce(new Error('Provider temporarily down')).mockResolvedValueOnce(empty);
        render(<LocaleProvider><BillingHistoryPanel context={context} /></LocaleProvider>);

        expect(await screen.findByRole('alert')).toHaveTextContent('Could not load billing history');
        expect(screen.queryByText('No audit events.')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
        expect(await screen.findByText('No audit events.')).toBeInTheDocument();
        expect(screen.getByText('No transactions.')).toBeInTheDocument();
        expect(apiMock).toHaveBeenCalledTimes(2);
    });

    it('does not fetch tenant billing history for an unauthorized viewer', () => {
        render(<LocaleProvider><BillingHistoryPanel context={{ ...context, permissions: ['tenant.view'] }} /></LocaleProvider>);
        expect(screen.queryByTestId('billing-history')).not.toBeInTheDocument();
        expect(apiMock).not.toHaveBeenCalled();
    });

    it('renders RTL labels and never trusts malformed paginated 200 envelopes', async () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        apiMock.mockResolvedValue({ data: { audit: [], transactions: [] } });
        render(<LocaleProvider><BillingHistoryPanel context={context} /></LocaleProvider>);
        expect(await screen.findByRole('alert')).toHaveTextContent('تعذر تحميل سجل الفوترة');
        expect(screen.queryByText('لا توجد أحداث تدقيق.')).not.toBeInTheDocument();
    });
});
