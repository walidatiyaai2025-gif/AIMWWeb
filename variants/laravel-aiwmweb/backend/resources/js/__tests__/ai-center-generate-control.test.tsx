import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AI_CENTER_GENERATE_OPERATION_ID, AiCenterGenerateControl } from '../ai-center-generate-control';
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
            <AiCenterGenerateControl
                context={value}
                prompts={[{ key: 'content.rewrite', title: 'Rewrite content' }]}
                sites={[{ id: 12, name: 'Alpha Site' }]}
            />
        </LocaleProvider>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${AI_CENTER_GENERATE_OPERATION_ID} AI Center Generate suggestion`, () => {
    it('submits the tenant-scoped generation request and renders the reviewable result', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            data: {
                operation_id: AI_CENTER_GENERATE_OPERATION_ID,
                before: 'Original article',
                after: 'Improved article',
                explanation: 'Improved structure.',
                confidence: 0.93,
                affected_fields: ['content'],
                provider: 'provider-test',
                model: 'model-test',
                correlation_id: '22222222-2222-4222-8222-222222222222',
                prompt_key: 'content.rewrite',
                site_id: 12,
            },
        }), { status: 200, headers: { 'content-type': 'application/json' } }));
        vi.stubGlobal('fetch', fetchMock);

        renderControl();
        const generate = screen.getByRole('button', { name: 'Generate suggestion' });
        expect(generate).toHaveAttribute('data-canonical-operation', AI_CENTER_GENERATE_OPERATION_ID);
        expect(generate).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Original value / current content'), { target: { value: 'Original article' } });
        fireEvent.change(screen.getByLabelText('Prompt key'), { target: { value: 'content.rewrite' } });
        fireEvent.change(screen.getByLabelText('Optional model'), { target: { value: 'model-test' } });
        fireEvent.change(screen.getByLabelText('Temperature'), { target: { value: '0.4' } });
        fireEvent.change(screen.getByLabelText('Max output tokens'), { target: { value: '900' } });
        fireEvent.change(screen.getByLabelText('Site'), { target: { value: '12' } });
        fireEvent.click(generate);

        expect(await screen.findByTestId('ai-generated-suggestion')).toHaveTextContent('Improved article');
        expect(screen.getByText('Improved structure.')).toBeInTheDocument();
        expect(screen.getByText('93%')).toBeInTheDocument();
        expect(screen.getByText('provider-test / model-test')).toBeInTheDocument();
        expect(screen.getByText('Session suggestions')).toBeInTheDocument();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe('/api/tenants/alpha/ai-center/generate');
        expect(fetchMock.mock.calls[0][1]?.method).toBe('POST');
        expect(JSON.parse(String(fetchMock.mock.calls[0][1]?.body))).toEqual({
            content: 'Original article',
            prompt_key: 'content.rewrite',
            model: 'model-test',
            temperature: 0.4,
            max_output_tokens: 900,
            site_id: 12,
        });
    });

    it('does not expose the control when ai.use is absent', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ permissions: ['tenant.view'] }));

        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(screen.queryByRole('button', { name: 'Generate suggestion' })).not.toBeInTheDocument();
    });

    it('keeps provider failures visible and does not manufacture a suggestion', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({
            message: 'No READY provider/model satisfies the requested AI capabilities.',
            code: 'model_unavailable',
        }), { status: 503, headers: { 'content-type': 'application/json' } })));

        renderControl();
        fireEvent.change(screen.getByLabelText('Original value / current content'), { target: { value: 'Original article' } });
        fireEvent.click(screen.getByRole('button', { name: 'Generate suggestion' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('No READY provider/model satisfies the requested AI capabilities.');
        expect(screen.queryByTestId('ai-generated-suggestion')).not.toBeInTheDocument();
    });
});
