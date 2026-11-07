import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { ImportExportWorkspace } from '../import-export-workspace';
import { LocaleProvider } from '../i18n';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view', 'content.edit'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
        ...overrides,
    };
}

function jsonResponse(payload: unknown, status = 200): Response {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'content-type': 'application/json' },
    });
}

function renderWorkspace(value = context()) {
    return render(
        <LocaleProvider>
            <ImportExportWorkspace context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('ImportExportWorkspace', () => {
    it('starts an export, polls the real transfer status and exposes download only after completion', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse([{ id: 7, name: 'Alpha WordPress', url: 'https://alpha.test' }]))
            .mockResolvedValueOnce(jsonResponse({ id: 22, site_id: 7, kind: 'export', state: 'queued', progress: 5 }, 202))
            .mockResolvedValueOnce(jsonResponse({ id: 22, site_id: 7, kind: 'export', state: 'completed', progress: 100, result: { content: 3 } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('Alpha WordPress — https://alpha.test')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Start export' }));

        const download = await screen.findByRole('link', { name: 'Download export JSON' }, { timeout: 6000 });
        expect(download).toHaveAttribute('href', '/api/v1/tenants/alpha/sites/7/transfers/22/download');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/v1/tenants/alpha/sites/7/transfers/export');
        expect(fetchMock.mock.calls[1]?.[1]?.method).toBe('POST');
        expect(fetchMock.mock.calls[2]?.[0]).toBe('/api/v1/tenants/alpha/sites/7/transfers/22');
    });

    it('uploads a validated version 1 JSON file through multipart and reports the persisted import result', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(jsonResponse([{ id: 7, name: 'Alpha WordPress', url: 'https://alpha.test' }]))
            .mockResolvedValueOnce(jsonResponse({ id: 23, site_id: 7, kind: 'import', state: 'queued', progress: 5 }, 202))
            .mockResolvedValueOnce(jsonResponse({ id: 23, site_id: 7, kind: 'import', state: 'completed', progress: 100, result: { imported: 2 } }));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();
        expect(await screen.findByText('Alpha WordPress — https://alpha.test')).toBeInTheDocument();

        const payload = JSON.stringify({ version: 1, content: [{ type: 'post', title: 'A' }, { type: 'page', title: 'B' }] });
        const file = new File([payload], 'content-export.json', { type: 'application/json' });
        Object.defineProperty(file, 'text', { value: async () => payload });
        fireEvent.change(screen.getByLabelText('JSON import file'), { target: { files: [file] } });
        fireEvent.click(screen.getByRole('button', { name: 'Start import' }));

        expect(await screen.findByText('Import completed. Items created: 2', {}, { timeout: 6000 })).toBeInTheDocument();
        const importCall = fetchMock.mock.calls.find(([url]) => url === '/api/v1/tenants/alpha/sites/7/transfers/import');
        expect(importCall?.[1]?.method).toBe('POST');
        expect(importCall?.[1]?.body).toBeInstanceOf(FormData);
        expect((importCall?.[1]?.body as FormData).get('file')).toBeInstanceOf(File);
        expect(((importCall?.[1]?.body as FormData).get('file') as File).name).toBe('content-export.json');
    });

    it('keeps import disabled without content.edit and fails closed for a foreign site endpoint', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace(context({
            permissions: ['tenant.view', 'content.view'],
            api: { sites: '/api/tenants/beta/sites' },
        }));

        expect(await screen.findByRole('alert')).toHaveTextContent('The active tenant site-read authority could not be verified.');
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
