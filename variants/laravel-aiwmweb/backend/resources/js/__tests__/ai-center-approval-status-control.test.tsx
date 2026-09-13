import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AI_CENTER_REFRESH_APPROVAL_STATUS_OPERATION_ID,
    AiCenterApprovalStatusControl,
} from '../ai-center-approval-status-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 7, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'ai.use'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
        ...overrides,
    };
}

function renderControl(value = context()) {
    return render(
        <LocaleProvider>
            <AiCenterApprovalStatusControl context={value} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${AI_CENTER_REFRESH_APPROVAL_STATUS_OPERATION_ID} AI Center Refresh state`, () => {
    it('rereads the authoritative current-user approval state without mutation', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 17, status: 'PENDING' } }), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 17, status: 'APPROVED' } }), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const refresh = await screen.findByRole('button', { name: 'Refresh state' });
        expect(refresh).toHaveAttribute('data-canonical-operation', AI_CENTER_REFRESH_APPROVAL_STATUS_OPERATION_ID);
        expect(screen.getByText('PENDING')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/ai-center/approval-status');
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[0][1]?.body).toBeUndefined();

        fireEvent.click(refresh);

        await waitFor(() => expect(screen.getByText('APPROVED')).toBeInTheDocument());
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/ai-center/approval-status');
        expect(fetchMock.mock.calls[1][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[1][1]?.body).toBeUndefined();
    });

    it('derives the request only from the active tenant and encodes the tenant segment', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 21, status: 'PENDING' } }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ tenant: { slug: 'alpha/../beta', name: 'Probe' } }));

        await screen.findByRole('button', { name: 'Refresh state' });
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha%2F..%2Fbeta/ai-center/approval-status');
    });

    it('fails closed without ai.use authority and never issues the read', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ permissions: ['tenant.view'] }));

        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: 'Refresh state' })).not.toBeInTheDocument();
    });

    it('does not fabricate a refresh surface when no owned approval exists or the read fails', async () => {
        const emptyFetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: null }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', emptyFetch);
        const empty = renderControl();

        await waitFor(() => expect(emptyFetch).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('button', { name: 'Refresh state' })).not.toBeInTheDocument();
        empty.unmount();

        const failedFetch = vi.fn().mockRejectedValue(new Error('approval read unavailable'));
        vi.stubGlobal('fetch', failedFetch);
        renderControl();

        await waitFor(() => expect(failedFetch).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('button', { name: 'Refresh state' })).not.toBeInTheDocument();
    });
});
