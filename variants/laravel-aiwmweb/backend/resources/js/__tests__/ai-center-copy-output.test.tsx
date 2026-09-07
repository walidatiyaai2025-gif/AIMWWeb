import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    AI_CENTER_COPY_OUTPUT_OPERATION_ID,
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
    { id: 'one', title: 'Rewrite title', promptKey: 'content.rewrite', output: 'Older suggestion' },
];

function renderControl(initialOutput = 'Structured suggestion', value = context(), initialHistory = history) {
    return render(
        <LocaleProvider>
            <AiCenterApprovalStatusControl
                context={value}
                initialHistory={initialHistory}
                initialOutput={initialOutput}
            />
        </LocaleProvider>,
    );
}

function stubApprovalRead() {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: null }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
    }));
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

beforeEach(() => {
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
});

afterEach(() => {
    vi.unstubAllGlobals();
    Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
});

describe(`${AI_CENTER_COPY_OUTPUT_OPERATION_ID} AI Center Copy output`, () => {
    it('copies the exact current output and reports success only after the browser confirms the clipboard write', async () => {
        const fetchMock = stubApprovalRead();
        let resolveWrite!: () => void;
        const pendingWrite = new Promise<void>((resolve) => { resolveWrite = resolve; });
        const writeText = vi.fn(() => pendingWrite);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

        renderControl('Exact structured suggestion');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        const copy = screen.getByRole('button', { name: 'Copy' });
        expect(copy).toHaveAttribute('data-canonical-operation', AI_CENTER_COPY_OUTPUT_OPERATION_ID);
        expect(screen.getByText('Exact structured suggestion')).toBeInTheDocument();

        fireEvent.click(copy);
        fireEvent.click(copy);

        expect(writeText).toHaveBeenCalledTimes(1);
        expect(writeText).toHaveBeenCalledWith('Exact structured suggestion');
        expect(copy).toBeDisabled();
        expect(copy).toHaveAttribute('aria-busy', 'true');
        expect(screen.queryByText('Suggestion copied.')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        resolveWrite();
        await pendingWrite;

        expect(await screen.findByRole('status')).toHaveTextContent('Suggestion copied.');
        expect(copy).not.toBeDisabled();
        expect(copy).toHaveAttribute('aria-busy', 'false');
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('never emits copy success when the clipboard write rejects', async () => {
        const fetchMock = stubApprovalRead();
        const writeText = vi.fn().mockRejectedValue(new Error('clipboard denied'));
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

        renderControl('Suggestion that must not fake success');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('The browser did not confirm the clipboard write');
        expect(screen.queryByText('Suggestion copied.')).not.toBeInTheDocument();
        expect(writeText).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('fails truthfully when the browser Clipboard API is unavailable', async () => {
        const fetchMock = stubApprovalRead();
        renderControl('Clipboard-required suggestion');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        fireEvent.click(screen.getByRole('button', { name: 'Copy' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('No copy success was reported.');
        expect(screen.queryByText('Suggestion copied.')).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('renders only for non-empty output and fails closed without ai.use', async () => {
        const fetchMock = stubApprovalRead();
        const { unmount } = renderControl('   ');

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('button', { name: 'Copy' })).not.toBeInTheDocument();
        unmount();

        fetchMock.mockClear();
        renderControl('Hidden suggestion', context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: 'Copy' })).not.toBeInTheDocument();
        expect(screen.queryByText('Hidden suggestion')).not.toBeInTheDocument();
    });

    it('preserves current output across Clear history but removes it with the already-terminal New session transition', async () => {
        const fetchMock = stubApprovalRead();
        Object.defineProperty(navigator, 'clipboard', { value: { writeText: vi.fn().mockResolvedValue(undefined) }, configurable: true });
        renderControl('Current output survives history clear');
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        expect(screen.getByRole('button', { name: 'Copy' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear history' }));
        expect(screen.getByText('Current output survives history clear')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Copy' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'New session' }));
        expect(screen.queryByText('Current output survives history clear')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Copy' })).not.toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
