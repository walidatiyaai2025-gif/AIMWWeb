import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AI_USAGE_AI_CENTER_OPERATION_ID, AiUsageAiCenterLinkControl } from '../ai-usage-ai-center-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'ai.viewUsage', 'ai.use'],
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <AiUsageAiCenterLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-AI-411CFF23F3 AI Usage to AI Center navigation', () => {
    it('renders the source-equivalent AI Center navigation for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /AI Center/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/ai-center');
        expect(link).toHaveAttribute('data-canonical-operation', AI_USAGE_AI_CENTER_OPERATION_ID);
        expect(AI_USAGE_AI_CENTER_OPERATION_ID).toBe('AIMW-AI-411CFF23F3');
    });

    it('encodes the active tenant slug instead of accepting a cross-tenant path fragment', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /AI Center/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/ai-center');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/ai-center');
    });

    it.each([
        ['missing tenant.view', ['ai.viewUsage', 'ai.use']],
        ['missing ai.viewUsage', ['tenant.view', 'ai.use']],
        ['missing ai.use', ['tenant.view', 'ai.viewUsage']],
    ])('fails closed when %s', (_label, permissions) => {
        renderControl(context('alpha', permissions));
        expect(screen.queryByRole('link', { name: /AI Center/i })).not.toBeInTheDocument();
    });
});
