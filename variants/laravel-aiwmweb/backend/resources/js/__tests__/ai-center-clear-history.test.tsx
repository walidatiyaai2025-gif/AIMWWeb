import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AI_CENTER_CLEAR_HISTORY_OPERATION_ID,
    AiCenterApprovalStatusControl,
    type AiCenterSessionHistoryEntry,
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

const history: AiCenterSessionHistoryEntry[] = [
    { id: 'one', title: 'Rewrite title', promptKey: 'content.rewrite', output: 'First structured suggestion' },
    { id: 'two', title: 'Improve summary', promptKey: 'content.summary', output: 'Second structured suggestion' },
];

function renderControl(initialHistory: AiCenterSessionHistoryEntry[] = history, value = context()) {
    return render(
        <LocaleProvider>
            <AiCenterApprovalStatusControl context={value} initialHistory={initialHistory} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${AI_CENTER_CLEAR_HISTORY_OPERATION_ID} AI Center Clear history`, () => {
    it('clears only in-memory session suggestions and emits no persistence or provider request', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: { id: 17, status: 'PENDING' } }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        fireEvent.change(screen.getByLabelText('Prompt key'), { target: { value: 'active.prompt' } });
        fireEvent.change(screen.getByLabelText('Original value / current content'), { target: { value: 'Current composer content' } });

        expect(await screen.findByText('PENDING')).toBeInTheDocument();
        expect(screen.getByText('First structured suggestion')).toBeInTheDocument();
        expect(screen.getByText('Second structured suggestion')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const clear = screen.getByRole('button', { name: 'Clear history' });
        expect(clear).toHaveAttribute('data-canonical-operation', AI_CENTER_CLEAR_HISTORY_OPERATION_ID);
        expect(clear).not.toBeDisabled();
        fireEvent.click(clear);

        expect(screen.queryByText('First structured suggestion')).not.toBeInTheDocument();
        expect(screen.queryByText('Second structured suggestion')).not.toBeInTheDocument();
        expect(screen.getByText('No session suggestions yet.')).toBeInTheDocument();
        expect(clear).toBeDisabled();
        expect(screen.getByLabelText('Prompt key')).toHaveValue('active.prompt');
        expect(screen.getByLabelText('Original value / current content')).toHaveValue('Current composer content');
        expect(screen.getByText('PENDING')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[0][1]?.body).toBeUndefined();
    });

    it('is disabled when the current browser session has no history', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: null }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl([]);

        const clear = screen.getByRole('button', { name: 'Clear history' });
        expect(clear).toHaveAttribute('data-canonical-operation', AI_CENTER_CLEAR_HISTORY_OPERATION_ID);
        expect(clear).toBeDisabled();
        expect(screen.getByText('No session suggestions yet.')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    });

    it('fails closed without ai.use and performs no read or history action', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(history, context({ permissions: ['tenant.view'] }));

        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: 'Clear history' })).not.toBeInTheDocument();
        expect(screen.queryByText('First structured suggestion')).not.toBeInTheDocument();
    });
});
