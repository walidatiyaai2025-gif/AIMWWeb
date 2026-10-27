import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { buildSystemHealthSummary, SystemHealthCopySummaryControl, type SystemHealthSnapshot } from '../system-health-copy-summary-control';
const context: FrontendContext = {
    user: { id: 10, name: 'Owner', email: 'owner@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' }, tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'diagnostics.view'], connectors: [], capabilities: {}, api: {}, actions: {},
};
const snapshot: SystemHealthSnapshot = { tenant: 'alpha', status: 'not_ready', checks: Object.fromEntries(
    ['app', 'database', 'redis', 'storage', 'queue', 'scheduler'].map((name) => [name, { status: name === 'redis' ? 'unavailable' : 'ok' }]),
) };
const show = (value = snapshot, ctx = context, loading = false) => render(<LocaleProvider>
    <SystemHealthCopySummaryControl context={ctx} snapshot={value} loading={loading} />
</LocaleProvider>);
afterEach(() => { cleanup(); localStorage.clear(); vi.restoreAllMocks(); });
describe('AIMW-BILL-48E69F42EB CopySummaryAsync', () => {
    it('copies actual health checks and confirms only after clipboard resolves', async () => {
        let finish!: () => void;
        const writeText = vi.fn((_text: string) => new Promise<void>((resolve) => { finish = resolve; }));
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        show();
        const button = screen.getByRole('button');
        expect(button).toHaveAttribute('data-canonical-operation', 'AIMW-BILL-48E69F42EB');
        fireEvent.click(button); fireEvent.click(button);
        expect(writeText).toHaveBeenCalledTimes(1);
        expect(writeText.mock.calls[0]).toEqual([buildSystemHealthSummary(snapshot)]);
        expect(screen.queryByRole('status')).toBeNull(); expect(button).toBeDisabled();
        finish();
        await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('summary copied'));
    });
    it('reports denial without exception details and permits retry', async () => {
        const writeText = vi.fn().mockRejectedValueOnce(new Error('secret-token')).mockResolvedValueOnce(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
        show(); fireEvent.click(screen.getByRole('button'));
        await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
        expect(document.body.textContent).not.toContain('secret-token'); expect(screen.queryByRole('status')).toBeNull();
        fireEvent.click(screen.getByRole('button'));
        await waitFor(() => expect(screen.getByRole('status')).toBeInTheDocument()); expect(screen.queryByRole('alert')).toBeNull();
    });
    it('handles missing clipboard honestly in Arabic', async () => {
        localStorage.setItem('aiwm.locale', 'ar');
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        show(); fireEvent.click(screen.getByRole('button', { name: 'نسخ ملخص صحة النظام' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('تعذر نسخ الملخص'));
    });
    it.each([
        [snapshot, context, true], [{ ...snapshot, tenant: 'foreign' }, context, false],
        [snapshot, { ...context, permissions: ['tenant.view'] }, false],
        [snapshot, { ...context, tenants: [] }, false], [{ ...snapshot, checks: {} }, context, false],
    ])('withholds copying without authoritative authorized data', (value, ctx, loading) => {
        show(value as SystemHealthSnapshot, ctx as FrontendContext, loading as boolean);
        expect(screen.queryByRole('button')).toBeNull();
    });
    it('exports only allowlisted fields and rejects unexpected statuses', () => {
        const extended = { ...snapshot, token: 'secret', checks: { ...snapshot.checks, credentials: { status: 'secret' } } };
        expect(buildSystemHealthSummary(extended)).not.toContain('secret');
        expect(buildSystemHealthSummary({ ...snapshot, status: 'secret' })).toBeNull();
        expect(buildSystemHealthSummary({ ...snapshot, checks: { ...snapshot.checks, redis: { status: 'secret' } } })).toBeNull();
    });
});
