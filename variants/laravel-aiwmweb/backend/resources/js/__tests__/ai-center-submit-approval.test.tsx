import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AI_CENTER_SUBMIT_APPROVAL_OPERATION_ID,
    AiCenterApprovalStatusControl,
    type AiCenterStructuredSuggestion,
} from '../ai-center-approval-status-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const suggestion: AiCenterStructuredSuggestion = {
    before: 'Original article',
    after: 'Improved article',
    explanation: 'Tightened the introduction and headings.',
    confidence: 0.91,
    affectedFields: ['title', 'content'],
    promptKey: 'content.rewrite',
    model: 'gpt-test',
    siteId: 42,
};

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

function renderControl(value = context(), initialSuggestion: AiCenterStructuredSuggestion | null = suggestion) {
    return render(
        <LocaleProvider>
            <AiCenterApprovalStatusControl context={value} initialSuggestion={initialSuggestion} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe(`${AI_CENTER_SUBMIT_APPROVAL_OPERATION_ID} AI Center Submit to approval queue`, () => {
    it('submits the exact structured proposal to the active tenant queue and reports server-confirmed success', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: null }), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 77, status: 'PENDING' } }), {
                status: 201,
                headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        const submit = await screen.findByRole('button', { name: 'Submit to approval queue' });
        expect(submit).toHaveAttribute('data-canonical-operation', AI_CENTER_SUBMIT_APPROVAL_OPERATION_ID);
        expect(screen.getByText('Original article')).toBeInTheDocument();
        expect(screen.getByText('Improved article')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Risk level'), { target: { value: 'Critical' } });
        fireEvent.change(screen.getByLabelText('Operation type'), { target: { value: 'AI.ContentRewrite' } });
        fireEvent.change(screen.getByLabelText('Approval title'), { target: { value: 'Rewrite homepage' } });
        fireEvent.click(submit);

        await screen.findByText('Structured proposal submitted to the approval queue.');
        expect(screen.getByText('PENDING')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha/ai-center/approvals');
        expect(fetchMock.mock.calls[1][1]?.method).toBe('POST');

        const body = JSON.parse(String(fetchMock.mock.calls[1][1]?.body));
        expect(body.request_key).toMatch(/^[0-9a-f-]{36}$/i);
        expect(body).toMatchObject({
            site_id: 42,
            operation_type: 'AI.ContentRewrite',
            title: 'Rewrite homepage',
            risk_level: 'Critical',
            before_content: 'Original article',
            after_content: 'Improved article',
            prompt_key: 'content.rewrite',
            model: 'gpt-test',
            explanation: 'Tightened the introduction and headings.',
            confidence: 0.91,
            affected_fields: ['title', 'content'],
        });
    });

    it('uses source defaults and encodes the authoritative tenant without accepting a caller tenant target', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: null }), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: { id: 78, status: 'PENDING' } }), {
                status: 201,
                headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ tenant: { slug: 'alpha/../beta', name: 'Probe' } }), { ...suggestion, siteId: null });
        fireEvent.change(await screen.findByLabelText('Operation type'), { target: { value: '   ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Submit to approval queue' }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][0]).toBe('/api/tenants/alpha%2F..%2Fbeta/ai-center/approvals');
        const body = JSON.parse(String(fetchMock.mock.calls[1][1]?.body));
        expect(body.operation_type).toBe('AI.ContentUpdate');
        expect(body.title).toBe('AI content proposal');
        expect(body.risk_level).toBe('High');
        expect(body.site_id).toBeNull();
        expect(String(fetchMock.mock.calls[1][0])).not.toContain('/tenants/beta/');
    });

    it('does not render a submit surface without a structured suggestion or ai.use authority', async () => {
        const emptyFetch = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: null }), {
            status: 200,
            headers: { 'content-type': 'application/json' },
        }));
        vi.stubGlobal('fetch', emptyFetch);
        const empty = renderControl(context(), null);

        await waitFor(() => expect(emptyFetch).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('button', { name: 'Submit to approval queue' })).not.toBeInTheDocument();
        empty.unmount();

        const deniedFetch = vi.fn();
        vi.stubGlobal('fetch', deniedFetch);
        renderControl(context({ permissions: ['tenant.view'] }));
        await waitFor(() => expect(deniedFetch).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: 'Submit to approval queue' })).not.toBeInTheDocument();
    });

    it('never reports optimistic success when the server rejects the approval', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(new Response(JSON.stringify({ data: null }), {
                status: 200,
                headers: { 'content-type': 'application/json' },
            }))
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'Approval submission rejected.' }), {
                status: 422,
                headers: { 'content-type': 'application/json' },
            }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        fireEvent.click(await screen.findByRole('button', { name: 'Submit to approval queue' }));

        await screen.findByRole('alert');
        expect(screen.getByRole('alert')).toHaveTextContent('Approval submission rejected.');
        expect(screen.queryByText('Structured proposal submitted to the approval queue.')).not.toBeInTheDocument();
        expect(screen.queryByText('PENDING')).not.toBeInTheDocument();
    });
});
