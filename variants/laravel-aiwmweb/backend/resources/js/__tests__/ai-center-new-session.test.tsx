import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AI_CENTER_NEW_SESSION_OPERATION_ID,
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

describe(`${AI_CENTER_NEW_SESSION_OPERATION_ID} AI Center New session`, () => {
    it('clears source-equivalent local draft and approval display state without issuing a mutation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 17, status: 'PENDING' } }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const promptKey = screen.getByLabelText('Prompt key');
        const content = screen.getByLabelText('Original value / current content');
        fireEvent.change(promptKey, { target: { value: 'content.rewrite' } });
        fireEvent.change(content, { target: { value: 'Existing article body' } });

        expect(await screen.findByText('PENDING')).toBeInTheDocument();
        expect(promptKey).toHaveValue('content.rewrite');
        expect(content).toHaveValue('Existing article body');
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const reset = screen.getByRole('button', { name: 'New session' });
        expect(reset).toHaveAttribute('data-canonical-operation', AI_CENTER_NEW_SESSION_OPERATION_ID);
        fireEvent.click(reset);

        expect(promptKey).toHaveValue('');
        expect(content).toHaveValue('');
        expect(screen.queryByText('PENDING')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Refresh state' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[0][1]?.body).toBeUndefined();
    });

    it('does not allow an older in-flight approval read to resurrect cleared session state', async () => {
        let resolveRead!: (response: Response) => void;
        const pendingRead = new Promise<Response>((resolve) => { resolveRead = resolve; });
        const fetchMock = vi.fn().mockReturnValue(pendingRead);
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        fireEvent.change(screen.getByLabelText('Prompt key'), { target: { value: 'stale.prompt' } });
        fireEvent.change(screen.getByLabelText('Original value / current content'), { target: { value: 'stale content' } });
        fireEvent.click(screen.getByRole('button', { name: 'New session' }));

        resolveRead(new Response(JSON.stringify({ data: { id: 99, status: 'APPROVED' } }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(screen.getByLabelText('Prompt key')).toHaveValue('');
        expect(screen.getByLabelText('Original value / current content')).toHaveValue('');
        expect(screen.queryByText('APPROVED')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Refresh state' })).not.toBeInTheDocument();
    });

    it('fails closed when the caller lacks ai.use and performs no read', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ permissions: ['tenant.view'] }));

        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: 'New session' })).not.toBeInTheDocument();
    });
});
