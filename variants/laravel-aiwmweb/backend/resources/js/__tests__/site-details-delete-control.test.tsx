import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ToastProvider } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SITE_DETAILS_DELETE_OPERATION_ID, SiteDetailsDeleteControl } from '../site-details-delete-control';

const navigate = vi.fn();
vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return { ...actual, useNavigate: () => navigate };
});

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['sites.manage'],
        connectors: [],
        capabilities: {},
        api: {
            sites: '/api/tenants/alpha/sites',
            'sites.detail.42': '/api/tenants/alpha/sites/42',
        },
        actions: {},
        ...overrides,
    };
}

function renderControl(value = context(), siteId: number | string = 42) {
    const queryClient = new QueryClient({
        defaultOptions: {
            queries: { retry: false },
            mutations: { retry: false },
        },
    });

    render(
        <MemoryRouter>
            <QueryClientProvider client={queryClient}>
                <LocaleProvider>
                    <ToastProvider>
                        <SiteDetailsDeleteControl context={value} siteId={siteId} />
                    </ToastProvider>
                </LocaleProvider>
            </QueryClientProvider>
        </MemoryRouter>,
    );

    return queryClient;
}

beforeEach(() => {
    window.localStorage.setItem('aiwm.locale', 'en');
    navigate.mockReset();
    document.head.querySelector('meta[name="csrf-token"]')?.remove();
    const csrf = document.createElement('meta');
    csrf.name = 'csrf-token';
    csrf.content = 'test-csrf-token';
    document.head.appendChild(csrf);
});

afterEach(() => {
    document.head.querySelector('meta[name="csrf-token"]')?.remove();
    cleanup();
    vi.restoreAllMocks();
});

describe('AIMW-BILL-BE4B8C3822 Site Details delete control', () => {
    it('binds the exact canonical operation, preserves CSRF/session semantics, and reports success only after authoritative reread proves absence', async () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(new Response(JSON.stringify([{ id: 7 }]), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }));

        renderControl();

        const deleteButton = screen.getByRole('button', { name: 'Delete site' });
        expect(deleteButton).toHaveAttribute('data-canonical-operation', SITE_DETAILS_DELETE_OPERATION_ID);
        expect(SITE_DETAILS_DELETE_OPERATION_ID).toBe('AIMW-BILL-BE4B8C3822');

        fireEvent.click(deleteButton);
        fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha/sites/42');
        const deleteInit = fetchMock.mock.calls[0]?.[1] as RequestInit;
        const headers = new Headers(deleteInit.headers);
        expect(deleteInit.method).toBe('DELETE');
        expect(deleteInit.credentials).toBe('same-origin');
        expect(headers.get('X-CSRF-TOKEN')).toBe('test-csrf-token');
        expect(headers.get('X-Requested-With')).toBe('XMLHttpRequest');
        expect(fetchMock.mock.calls[1]?.[0]).toBe('/api/tenants/alpha/sites');
        expect(fetchMock.mock.calls[1]?.[1]).toMatchObject({ credentials: 'same-origin' });

        await waitFor(() => expect(navigate).toHaveBeenCalledWith('/tenants/alpha/sites'));
        expect(await screen.findByRole('status')).toHaveTextContent('Site deleted and reconciled from the server.');
    });

    it('fails closed when authoritative reread still contains the target site', async () => {
        vi.spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(new Response(JSON.stringify([{ id: 42 }]), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }));

        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Delete site' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));

        const alert = await screen.findByRole('alert');
        expect(alert).toHaveTextContent('authoritative refresh failed');
        expect(navigate).not.toHaveBeenCalled();
    });

    it('cancel performs no mutation', () => {
        const fetchMock = vi.spyOn(globalThis, 'fetch');
        renderControl();

        fireEvent.click(screen.getByRole('button', { name: 'Delete site' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.queryByRole('button', { name: 'Confirm delete' })).not.toBeInTheDocument();
    });

    it('suppresses duplicate submission while the delete is pending', async () => {
        let resolveDelete!: (response: Response) => void;
        const fetchMock = vi.spyOn(globalThis, 'fetch')
            .mockImplementationOnce(() => new Promise<Response>((resolve) => { resolveDelete = resolve; }))
            .mockResolvedValueOnce(new Response(JSON.stringify([]), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }));

        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Delete site' }));
        const confirm = screen.getByRole('button', { name: 'Confirm delete' });
        fireEvent.click(confirm);

        await waitFor(() => expect(confirm).toBeDisabled());
        fireEvent.click(confirm);
        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolveDelete(new Response(null, { status: 204 }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await waitFor(() => expect(navigate).toHaveBeenCalledWith('/tenants/alpha/sites'));
    });

    it('does not render without sites.manage or with an invalid direct site id', () => {
        const limited = renderControl(context({ permissions: ['sites.view'] }));
        expect(screen.queryByRole('button', { name: 'Delete site' })).not.toBeInTheDocument();
        limited.clear();
        cleanup();

        renderControl(context(), 'not-a-number');
        expect(screen.queryByRole('button', { name: 'Delete site' })).not.toBeInTheDocument();
    });

    it('rejects foreign or synthesized server-advertised mutation and reread endpoints', () => {
        renderControl(context({
            api: {
                sites: '/api/tenants/alpha/sites',
                'sites.detail.42': '/api/tenants/beta/sites/42',
            },
        }));
        expect(screen.queryByRole('button', { name: 'Delete site' })).not.toBeInTheDocument();
        cleanup();

        renderControl(context({
            api: {
                sites: '/api/tenants/beta/sites',
                'sites.detail.42': '/api/tenants/alpha/sites/42',
            },
        }));
        expect(screen.queryByRole('button', { name: 'Delete site' })).not.toBeInTheDocument();
    });

    it('URL-encodes the server-derived active tenant and never escapes to a foreign tenant route', async () => {
        const value = context({
            tenant: { slug: 'alpha/../beta', name: 'Encoded tenant' },
            tenants: [{ slug: 'alpha/../beta', name: 'Encoded tenant' }],
            api: {
                sites: '/api/tenants/alpha%2F..%2Fbeta/sites',
                'sites.detail.42': '/api/tenants/alpha%2F..%2Fbeta/sites/42',
            },
        });
        const fetchMock = vi.spyOn(globalThis, 'fetch')
            .mockResolvedValueOnce(new Response(null, { status: 204 }))
            .mockResolvedValueOnce(new Response(JSON.stringify([]), {
                status: 200,
                headers: { 'Content-Type': 'application/json' },
            }));

        renderControl(value);
        fireEvent.click(screen.getByRole('button', { name: 'Delete site' }));
        fireEvent.click(screen.getByRole('button', { name: 'Confirm delete' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/api/tenants/alpha%2F..%2Fbeta/sites/42');
        await waitFor(() => expect(navigate).toHaveBeenCalledWith('/tenants/alpha%2F..%2Fbeta/sites'));
        expect(navigate).not.toHaveBeenCalledWith('/tenants/beta/sites');
    });
});