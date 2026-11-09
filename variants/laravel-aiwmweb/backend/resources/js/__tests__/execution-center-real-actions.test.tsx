import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    authorizedExecutionAction,
    ExecutionCenterRowActions,
    ExecutionDateRangeWarning,
    isValidExecutionDateRange,
    readExecutionUrlState,
    writeExecutionUrlState,
} from '../execution-center-real-actions';

const context = (): FrontendContext => ({
    user: { id: 10, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'operations.manage', 'execution.view'],
    connectors: [],
    capabilities: {},
    api: { execution: '/tenants/alpha/admin/operations' },
    actions: {},
});

const response = (value: unknown, code = 200) => new Response(JSON.stringify(value), {
    status: code, headers: { 'content-type': 'application/json' },
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.localStorage.removeItem('aiwm.locale');
    window.history.replaceState(null, '', '/');
});

describe('Execution Center authoritative actions (#1614)', () => {
    it('does not offer unsafe, foreign or unauthorized commands', () => {
        const owner = context();
        expect(authorizedExecutionAction(owner, { id: 9, status: 'completed', can_cancel: true }, 'cancel')).toBeNull();
        expect(authorizedExecutionAction(owner, { id: 9, status: 'failed', can_retry: false }, 'retry')).toBeNull();
        expect(authorizedExecutionAction(owner, { id: '9', status: 'queued', can_cancel: true }, 'cancel')).toBeNull();
        expect(authorizedExecutionAction(
            { ...owner, api: { execution: '/tenants/beta/admin/operations' } },
            { id: 9, status: 'queued', can_cancel: true },
            'cancel',
        )).toBeNull();
        expect(authorizedExecutionAction(
            { ...owner, permissions: ['execution.view'] },
            { id: 9, status: 'queued', can_cancel: true },
            'cancel',
        )).toBeNull();
    });

    it('confirms cancellation before sending a same-origin, tenant-scoped POST and reconciles', async () => {
        const fetchMock = vi.fn().mockResolvedValue(response({ id: 7, status: 'cancelled' }));
        const confirmMock = vi.fn().mockReturnValue(true);
        const onReconcile = vi.fn().mockResolvedValue(undefined);
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('confirm', confirmMock);
        render(<LocaleProvider><ExecutionCenterRowActions context={context()}
            row={{ id: 7, status: 'queued', can_cancel: true, can_retry: false }}
            onReconcile={onReconcile} /></LocaleProvider>);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel execution 7' }));
        await waitFor(() => expect(onReconcile).toHaveBeenCalledTimes(1));
        expect(confirmMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/tenants/alpha/admin/operations/7/cancel');
        expect(fetchMock.mock.calls[0][1].method).toBe('POST');
        expect(screen.getByRole('status')).toHaveTextContent('Server state verified.');
    });

    it('does not mutate if destructive confirmation is refused', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('confirm', vi.fn().mockReturnValue(false));
        render(<LocaleProvider><ExecutionCenterRowActions context={context()}
            row={{ id: 8, status: 'queued', can_cancel: true }}
            onReconcile={vi.fn()} /></LocaleProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel execution 8' }));
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('retries only server-authorized failed rows and keeps reconciliation failure honest', async () => {
        const fetchMock = vi.fn().mockResolvedValue(response({ id: 12, status: 'retrying' }));
        vi.stubGlobal('fetch', fetchMock);
        render(<LocaleProvider><ExecutionCenterRowActions context={context()}
            row={{ id: 12, status: 'failed', can_retry: true, can_cancel: false }}
            onReconcile={vi.fn().mockRejectedValue(new Error('readback unavailable'))} /></LocaleProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Retry execution 12' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('refresh failed'));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/tenants/alpha/admin/operations/12/retry');
    });

    it('preserves unrelated tenant/site query params and restores bounded shareable state', () => {
        window.history.replaceState(null, '', '/tenants/alpha/module/execution?site=4&tab=work');
        writeExecutionUrlState({
            page: 2, pageSize: 10, search: 'report.export', status: 'failed',
            type: 'report.', dateFrom: '2026-11-01', dateTo: '2026-11-08',
        });
        expect(window.location.search).toContain('site=4');
        expect(window.location.search).toContain('tab=work');
        expect(readExecutionUrlState()).toEqual({
            page: 2, pageSize: 10, search: 'report.export', status: 'failed',
            type: 'report.', dateFrom: '2026-11-01', dateTo: '2026-11-08',
        });
        window.history.replaceState(null, '', '/tenants/alpha/module/execution?page=-4&per_page=1000&status=unknown');
        expect(readExecutionUrlState().page).toBe(1);
        expect(readExecutionUrlState().pageSize).toBe(25);
        expect(readExecutionUrlState().status).toBe('');
        window.history.replaceState(null, '', '/tenants/alpha/module/execution?page=999999999999&per_page=50&site=4');
        expect(readExecutionUrlState().page).toBe(1);
        expect(readExecutionUrlState().pageSize).toBe(50);
        window.history.replaceState(null, '', '/tenants/alpha/module/execution?page=1000000&per_page=100');
        expect(readExecutionUrlState().page).toBe(1000000);
    });
    it('validates actual Gregorian execution dates including leap years and rejects inverted drafts', () => {
        expect(isValidExecutionDateRange('2024-02-29', '2024-02-29')).toBe(true);
        expect(isValidExecutionDateRange('2000-02-29', '2026-11-09')).toBe(true);
        expect(isValidExecutionDateRange('', '2026-11-09')).toBe(true);
        expect(isValidExecutionDateRange('2026-11-09', '')).toBe(true);
        expect(isValidExecutionDateRange('1900-02-29', '')).toBe(false);
        expect(isValidExecutionDateRange('2026-02-29', '')).toBe(false);
        expect(isValidExecutionDateRange('2026-04-31', '')).toBe(false);
        expect(isValidExecutionDateRange('0000-01-01', '')).toBe(false);
        expect(isValidExecutionDateRange('2026-12-01', '2026-11-01')).toBe(false);
        expect(isValidExecutionDateRange('2026-11-09', 'not-a-date')).toBe(false);
    });

    it('shows an accessible correction hint in both RTL Arabic and English for invalid draft ranges', () => {
        const invalid = { dateFrom: '2026-11-09', dateTo: '2026-11-01' };
        const { rerender } = render(<ExecutionDateRangeWarning {...invalid} locale="en" />);
        expect(screen.getByRole('alert')).toHaveTextContent('Invalid date range');
        expect(screen.getByRole('alert')).toHaveAttribute('id', 'execution-date-range-warning');
        rerender(<ExecutionDateRangeWarning {...invalid} locale="ar" />);
        expect(screen.getByRole('alert')).toHaveTextContent('نطاق التاريخ غير صالح');
        rerender(<ExecutionDateRangeWarning dateFrom="2026-11-01" dateTo="2026-11-09" locale="ar" />);
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('restores valid URL filters without submitting impossible or reversed dates to Laravel', () => {
        window.history.replaceState(null, '', '/tenants/alpha/module/execution?site=3&status=failed&page=4&per_page=50&date_from=2026-11-09&date_to=2026-11-01');
        let state = readExecutionUrlState();
        expect(state).toMatchObject({
            status: 'failed', page: 4, pageSize: 50,
            dateFrom: '2026-11-09', dateTo: '',
        });
        expect(new URLSearchParams(window.location.search).get('site')).toBe('3');

        window.history.replaceState(null, '', '/tenants/alpha/module/execution?site=3&date_from=2026-02-29&date_to=2024-02-29');
        state = readExecutionUrlState();
        expect(state.dateFrom).toBe('');
        expect(state.dateTo).toBe('2024-02-29');

        window.history.replaceState(null, '', '/tenants/alpha/module/execution?date_from=2024-02-29&date_to=2024-03-01');
        state = readExecutionUrlState();
        expect(state.dateFrom).toBe('2024-02-29');
        expect(state.dateTo).toBe('2024-03-01');
    });
});
