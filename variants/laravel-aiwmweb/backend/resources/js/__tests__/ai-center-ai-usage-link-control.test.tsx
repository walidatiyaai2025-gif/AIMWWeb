import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AI_CENTER_AI_USAGE_OPERATION_ID, AiCenterAiUsageLinkControl } from '../ai-center-ai-usage-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'ai.use', 'ai.viewUsage'],
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
                <AiCenterAiUsageLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-AI-331ED9D5EE AI Center to AI Usage navigation', () => {
    it('renders the source-equivalent AI Usage & Cost navigation for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /AI Usage & Cost/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/ai-usage');
        expect(link).toHaveAttribute('data-canonical-operation', AI_CENTER_AI_USAGE_OPERATION_ID);
        expect(AI_CENTER_AI_USAGE_OPERATION_ID).toBe('AIMW-AI-331ED9D5EE');
    });

    it('encodes the active tenant slug instead of accepting a cross-tenant path fragment', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /AI Usage & Cost/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/module/ai-usage');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/module/ai-usage');
    });

    it.each([
        ['missing tenant.view', ['ai.use', 'ai.viewUsage']],
        ['missing ai.use', ['tenant.view', 'ai.viewUsage']],
        ['missing ai.viewUsage', ['tenant.view', 'ai.use']],
    ])('fails closed when %s', (_label, permissions) => {
        renderControl(context('alpha', permissions));
        expect(screen.queryByRole('link', { name: /AI Usage & Cost/i })).not.toBeInTheDocument();
    });
});
