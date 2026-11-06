import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AiOperationsLiveReport } from '../ai-operations-live-report';
import { LocaleProvider } from '../i18n';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('read-only live AI operations', () => {
    it('loads only when requested, displays persisted item state counts and warns about sync queue', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: {
                runtime: { ai_enabled: false, preflight_passed: false, cron_enabled: false, queue_driver: 'sync' },
                batches: [{
                    id: '12345678-1234-4234-8234-123456789abc', state: 'pending', site_id: 7,
                    completed: 17, total: 50, running: 1, pending: 32,
                    paused: 0, failed: 0, needs_reconciliation: 0,
                    updated_at: '2026-11-06 07:00:00', resume_after: null,
                }],
            },
        }), { headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);
        render(<LocaleProvider><AiOperationsLiveReport tenant="alpha" /></LocaleProvider>);
        expect(fetchMock).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Show live AI report' }));
        expect(await screen.findByText('17/50')).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent('sync queue runs Laravel jobs inside HTTP requests');
        expect(screen.getByText('12345678')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/ai-batches');
        expect(fetchMock.mock.calls[0][1].method).toBeUndefined();
    });

    it('surfaces a real authorization error rather than a fake empty task list', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            message: 'Forbidden',
        }), { status: 403, headers: { 'content-type': 'application/json' } })));
        render(<LocaleProvider><AiOperationsLiveReport tenant="alpha" /></LocaleProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Show live AI report' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Forbidden');
        expect(screen.queryByText('No saved AI batches for this user.')).not.toBeInTheDocument();
    });
});
