import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { POSTS_EXECUTION_OPERATION_ID, PostsExecutionLinkControl } from '../posts-execution-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions = ['tenant.view', 'content.view', 'operations.manage', 'execution.view'],
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
                <PostsExecutionLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-AUTO-06CF784553 Posts to Execution Center navigation', () => {
    it('renders the canonical Execution Center navigation for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Execution Center/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/execution');
        expect(link).toHaveAttribute('data-canonical-operation', POSTS_EXECUTION_OPERATION_ID);
        expect(POSTS_EXECUTION_OPERATION_ID).toBe('AIMW-AUTO-06CF784553');
    });

    it('encodes the active tenant slug instead of accepting a cross-tenant path fragment', () => {
        renderControl(context('alpha/../foreign'));

        const link = screen.getByRole('link', { name: /Execution Center/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fforeign/module/execution');
        expect(link.getAttribute('href')).not.toBe('/tenants/foreign/module/execution');
    });

    it.each([
        ['missing tenant.view', ['content.view', 'operations.manage', 'execution.view']],
        ['missing content.view', ['tenant.view', 'operations.manage', 'execution.view']],
        ['missing operations.manage', ['tenant.view', 'content.view', 'execution.view']],
        ['missing execution.view', ['tenant.view', 'content.view', 'operations.manage']],
    ])('fails closed when %s', (_label, permissions) => {
        renderControl(context('alpha', permissions));
        expect(screen.queryByRole('link', { name: /Execution Center/i })).not.toBeInTheDocument();
    });
});
