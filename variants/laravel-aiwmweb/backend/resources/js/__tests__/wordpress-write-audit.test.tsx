import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import type { FrontendContext } from '../core';
import { apiRequest } from '../core';
import { safeInspectorBundle, type InspectorServer } from '../runtime-inspector-panel';
import { WordPressWriteAuditPanel, type WpSmokeRun } from '../wordpress-write-audit-panel';

vi.mock('../core', async importOriginal => ({
    ...(await importOriginal<typeof import('../core')>()),
    apiRequest: vi.fn(),
}));

const ctx: FrontendContext = {
    tenant: { slug: 'alpha', name: 'Private Tenant' },
    tenants: [{ slug: 'alpha', name: 'Private Tenant' }],
    user: { id: 1, name: 'Owner', email: 'owner@private.test' },
    permissions: ['diagnostics.view', 'content.edit', 'content.manage'],
    connectors: [], capabilities: {}, api: {}, actions: {},
};

function mount(siteIds = [5], context = ctx) {
    const onComplete = vi.fn();
    render(<LocaleProvider>
        <WordPressWriteAuditPanel context={context} siteIds={siteIds} onComplete={onComplete} />
    </LocaleProvider>);
    return onComplete;
}

afterEach(() => {
    cleanup();
    vi.resetAllMocks();
});

describe('WordPress write audit consent and redaction', () => {
    it('never writes on render or without explicit user consent', async () => {
        mount();
        const run = screen.getByRole('button', { name: /Run all disposable WordPress tests/i });
        expect(run).toBeDisabled();
        expect(apiRequest).not.toHaveBeenCalled();
        fireEvent.click(run);
        expect(apiRequest).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('checkbox'));
        expect(run).toBeEnabled();

        vi.mocked(apiRequest).mockImplementation(async (_url, options) => {
            const payload = JSON.parse(String(options?.body));
            return {
                schema: 'aiwm.wordpress-write-smoke.v1',
                transport: 'native_wordpress_rest',
                resource: payload.resource,
                state: 'passed', create_verified: true, save_verified: true,
                delete_verified: true, cleanup_required: false, reason: 'none',
            };
        });
        fireEvent.click(run);
        await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(6));
        expect(screen.getAllByText('passed')).toHaveLength(6);
        for (const [url, options] of vi.mocked(apiRequest).mock.calls) {
            expect(url).toBe('/tenants/alpha/runtime-inspector/wordpress-write-check');
            expect(options?.method).toBe('POST');
            const payload = JSON.parse(String(options?.body));
            expect(payload.site_id).toBe(5);
            expect(payload.confirmation).toBe('RUN_DISPOSABLE_WORDPRESS_TEST');
            expect(payload).not.toHaveProperty('remote_id');
            expect(payload).not.toHaveProperty('content');
        }
    });

    it('stops remote calls immediately when cleanup is uncertain and shows recovery hint', async () => {
        const onComplete = mount();
        vi.mocked(apiRequest).mockResolvedValue({
            schema: 'aiwm.wordpress-write-smoke.v1',
            transport: 'native_wordpress_rest',
            resource: 'posts',
            state: 'needs_reconciliation', create_verified: false, save_verified: false,
            delete_verified: false, cleanup_required: true, reason: 'cleanup_not_verified',
            recovery: { marker: 'aiwm-probe-privatecanary', known_objects: [{ resource: 'posts', remote_id: 99 }] },
        });
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: /Run all disposable WordPress tests/i }));
        await waitFor(() => expect(onComplete).toHaveBeenCalledWith(expect.objectContaining({
            completed: false, results: [expect.objectContaining({ state: 'needs_reconciliation' })],
        })));
        expect(apiRequest).toHaveBeenCalledTimes(1);
        expect(screen.getByText('aiwm-probe-privatecanary')).toBeInTheDocument();
    });

    it('does not allow selecting a foreign or missing site implicitly', () => {
        mount([5, 6]);
        const button = screen.getByRole('button', { name: /Run all disposable WordPress tests/i });
        fireEvent.click(screen.getByRole('checkbox'));
        expect(button).toBeDisabled();
        fireEvent.change(screen.getByRole('combobox'), { target: { value: '6' } });
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(button).toBeDisabled();
        expect(apiRequest).not.toHaveBeenCalled();
    });

    it('exports only allowlisted booleans and states, without recovery IDs/markers or remote bodies', () => {
        const run: WpSmokeRun = {
            site_id: 5, completed: false,
            results: [{
                schema: 'aiwm.wordpress-write-smoke.v1', transport: 'native_wordpress_rest',
                resource: 'posts', state: 'needs_reconciliation',
                create_verified: true, save_verified: true, delete_verified: false,
                cleanup_required: true, reason: 'cleanup_not_verified',
                recovery: { marker: 'TOP_SECRET_RECOVERY_MARKER', known_objects: [{ resource: 'posts', remote_id: 99999 }] },
            }],
        };
        const server = {
            schema: 'aiwm.runtime-inspector.v1',
            generated_at: '2026-11-07T10:00:00Z',
            safety: { redacted: true, read_only: true },
            release: { version: '1.0.418', sha: 'c0ed67c336d528706c57a0b862b573e13d1ac6b6' },
            runtime: { php_version: '8.3', laravel_version: '13.37.0', database: 'reachable',
                database_driver: 'mysql', queue_driver: 'sync', cache_driver: 'file', extensions: {} },
            site_count: 0, sites_truncated: false, sites: [],
        } as InspectorServer;
        const exportObject = safeInspectorBundle(server, [], ctx, null, run);
        const json = JSON.stringify(exportObject);
        expect(exportObject.wordpress_write_smoke!.results[0]).toMatchObject({
            resource: 'posts', state: 'needs_reconciliation', cleanup_required: true,
            delete_verified: false,
        });
        expect(json).not.toContain('TOP_SECRET_RECOVERY_MARKER');
        expect(json).not.toContain('99999');
        expect(json).not.toContain('owner@private.test');
        expect(json).not.toContain('remote_id');
    });
});
