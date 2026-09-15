import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AI_CENTER_APPROVAL_QUEUE_OPERATION, AiCenterApprovalQueueLink } from '../ai-center-approval-queue-link';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha', permissions = ['tenant.view', 'ai.use', 'approvals.view']): FrontendContext => ({
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
                <AiCenterApprovalQueueLink context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-AI-991683D92C AI Center Approval queue navigation', () => {
    it('renders the canonical source-equivalent control for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Approval queue/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/approvals');
        expect(link).toHaveAttribute('data-canonical-operation', AI_CENTER_APPROVAL_QUEUE_OPERATION);
        expect(AI_CENTER_APPROVAL_QUEUE_OPERATION).toBe('AIMW-AI-991683D92C');
    });

    it('encodes the active tenant slug and never accepts a cross-tenant path fragment', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /Approval queue/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/approvals');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/approvals');
    });

    it.each([
        ['missing ai.use', ['tenant.view', 'approvals.view']],
        ['missing tenant.view', ['ai.use', 'approvals.view']],
        ['missing approvals.view', ['tenant.view', 'ai.use']],
    ])('fails closed when %s', (_label, permissions) => {
        renderControl(context('alpha', permissions));
        expect(screen.queryByRole('link', { name: /Approval queue/i })).not.toBeInTheDocument();
    });
});
