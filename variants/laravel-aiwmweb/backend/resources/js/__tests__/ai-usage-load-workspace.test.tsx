import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AI_USAGE_LOAD_OPERATION_ID, AiUsageLoadWorkspace } from '../ai-usage-load-workspace';
import type { FrontendContext, WorkspaceRoute } from '../core';
import { LocaleProvider } from '../i18n';

const endpoint = '/api/v1/tenants/alpha/ai/usage';

const route: WorkspaceRoute = {
    key: 'ai-usage',
    path: '/module/ai-usage',
    group: 'ai',
    icon: '▥',
    label: { en: 'AI Usage & Cost', ar: 'استخدام وتكلفة الذكاء' },
    description: { en: 'Usage', ar: 'الاستخدام' },
    apiKey: 'ai-usage',
    permission: 'ai.viewUsage',
};

const context = (permissions = ['tenant.view', 'ai.viewUsage']): FrontendContext => ({
    user: { id: 7, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: { 'ai-usage': endpoint },
    actions: {},
});

const payload = (overrides: Record<string, unknown> = {}) => ({
    summary: {
        total_calls: 1,
        successful_calls: 1,
        success_rate: 1,
        input_units: 12,
        output_units: 8,
        estimated_cost: 0.003,
        actual_cost: 0.003,
    },
    providers: [],
    workflows: [],
    sites: [{ id: 17, name: 'Alpha Site' }],
    recent: [{
        id: 101,
        site_id: 17,
        provider: 'openai',
        model: 'gpt-test',
        workflow: 'rewrite',
        input_units: 12,
        output_units: 8,
        estimated_cost: 0.003,
        status: 'succeeded',
        failure_kind: null,
        created_at: '2026-08-31T06:00:00Z',
    }],
    data: [],
    total: 1,
    ...overrides,
});

function ok(body = payload()) {
    return Promise.resolve(new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'content-type': 'application/json' },
    }));
}

function renderWorkspace(value = context()) {
    return render(
        <LocaleProvider>
            <AiUsageLoadWorkspace context={value} route={route} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${AI_USAGE_LOAD_OPERATION_ID} AI Usage LoadAsync`, () => {
    it('performs the initial authoritative load and exposes the exact refresh operation marker', async () => {
        const fetchMock = vi.fn().mockImplementation(() => ok());
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();

        expect(await screen.findByText('openai / gpt-test')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(endpoint);
        expect(fetchMock.mock.calls[0][1]?.method).toBeUndefined();
        expect(fetchMock.mock.calls[0][1]?.body).toBeUndefined();

        const refresh = screen.getByRole('button', { name: /Refresh/i });
        expect(refresh).toHaveAttribute('data-canonical-operation', AI_USAGE_LOAD_OPERATION_ID);
        expect(AI_USAGE_LOAD_OPERATION_ID).toBe('AIMW-BILL-258E431558');
    });

    it('reloads through the same tenant endpoint with the selected site filter', async () => {
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => ok())
            .mockImplementationOnce(() => ok(payload({ total: 0, recent: [] })));
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();
        const siteFilter = await screen.findByLabelText('Choose site to filter usage history');
        expect(siteFilter).toHaveValue('');
        expect(screen.getAllByText('Alpha Site')).toHaveLength(2);

        fireEvent.change(siteFilter, { target: { value: '17' } });

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][0]).toBe(`${endpoint}?site=17`);
        expect(await screen.findByText('No AI calls yet')).toBeInTheDocument();
    });

    it('keeps the last successful snapshot visible when refresh fails and provides a real retry', async () => {
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => ok())
            .mockResolvedValueOnce(new Response(JSON.stringify({ message: 'usage backend unavailable' }), {
                status: 503,
                headers: { 'content-type': 'application/json' },
            }))
            .mockImplementationOnce(() => ok());
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace();
        expect(await screen.findByText('openai / gpt-test')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /^↻ Refresh$/i }));

        expect(await screen.findByText('Refresh failed — showing last successful data')).toBeInTheDocument();
        expect(screen.getByText('openai / gpt-test')).toBeInTheDocument();
        expect(screen.getByRole('alert')).toHaveTextContent('usage backend unavailable');

        fireEvent.click(screen.getByRole('button', { name: 'Retry refresh' }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3));
        await waitFor(() => expect(screen.queryByText('Refresh failed — showing last successful data')).not.toBeInTheDocument());
        expect(screen.getByText('openai / gpt-test')).toBeInTheDocument();
    });

    it('fails closed without the source read permission and makes no request', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderWorkspace(context(['tenant.view']));

        expect(screen.getByText('AI usage permission required')).toBeInTheDocument();
        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: /Refresh/i })).not.toBeInTheDocument();
    });
});
