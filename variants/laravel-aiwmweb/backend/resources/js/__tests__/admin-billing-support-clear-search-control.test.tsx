import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    ADMIN_BILLING_SUPPORT_CLEAR_SEARCH_OPERATION_ID,
    AdminBillingSupportClearSearchControl,
} from '../admin-billing-support-clear-search-control';
import { workspaceRoutes } from '../core';

describe('canonical Billing Support ClearSearchAsync control AIMW-BILL-B4F030B126', () => {
    it('binds the workspace to the server-issued billing-support API key', () => {
        const route = workspaceRoutes.find((candidate) => candidate.key === 'admin-billing-support');

        expect(route?.path).toBe('/admin/billing-support');
        expect(route?.apiKey).toBe('admin-billing-support');
    });

    it('renders even when the current search is blank once the authoritative endpoint is wired', () => {
        render(
            <AdminBillingSupportClearSearchControl
                available
                onClearRequested={() => undefined}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear' });
        expect(ADMIN_BILLING_SUPPORT_CLEAR_SEARCH_OPERATION_ID).toBe('AIMW-BILL-B4F030B126');
        expect(button).toHaveAttribute('data-canonical-operation', ADMIN_BILLING_SUPPORT_CLEAR_SEARCH_OPERATION_ID);
    });

    it('fails closed when the authoritative billing-support endpoint is unavailable', () => {
        render(
            <AdminBillingSupportClearSearchControl
                available={false}
                onClearRequested={() => undefined}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Clear' })).not.toBeInTheDocument();
    });

    it('suppresses duplicate clear requests while the authoritative reread is in flight', async () => {
        let resolveClear: (() => void) | undefined;
        const pending = new Promise<void>((resolve) => { resolveClear = resolve; });
        const clear = vi.fn(() => pending);

        render(
            <AdminBillingSupportClearSearchControl
                available
                onClearRequested={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear' });
        fireEvent.click(button);
        fireEvent.click(button);

        expect(clear).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();

        resolveClear?.();
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('treats a cross-tenant authoritative 404 as failure and remains retryable', async () => {
        const clear = vi.fn()
            .mockRejectedValueOnce(new Error('404 cross-tenant tenant not found'))
            .mockResolvedValueOnce(undefined);

        render(
            <AdminBillingSupportClearSearchControl
                available
                onClearRequested={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'Clear' });
        fireEvent.click(button);

        expect(await screen.findByRole('alert')).toHaveTextContent('authoritative reload failed');
        await waitFor(() => expect(button).not.toBeDisabled());

        fireEvent.click(button);
        await waitFor(() => expect(clear).toHaveBeenCalledTimes(2));
        await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    });

    it('respects externally busy state and preserves Arabic copy', () => {
        const clear = vi.fn();

        render(
            <AdminBillingSupportClearSearchControl
                available
                busy
                locale="ar"
                onClearRequested={clear}
            />,
        );

        const button = screen.getByRole('button', { name: 'مسح' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(clear).not.toHaveBeenCalled();
    });
});
