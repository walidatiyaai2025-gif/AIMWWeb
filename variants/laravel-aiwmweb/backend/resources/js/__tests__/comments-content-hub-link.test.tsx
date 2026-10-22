import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { COMMENTS_CONTENT_HUB_LINK_OPERATION, CommentsContentHubLink } from '../comments-content-hub-link';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha', permissions = ['tenant.view', 'content.view']): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const renderControl = (value: FrontendContext) => render(
    <MemoryRouter>
        <LocaleProvider>
            <CommentsContentHubLink context={value} />
        </LocaleProvider>
    </MemoryRouter>,
);

describe('AIMW-COMM-A0D005681B comments content hub link', () => {
    it('renders the source-equivalent Content hub control for the authoritative active tenant', () => {
        renderControl(context());

        const link = screen.getByRole('link', { name: /Content hub/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/content');
        expect(link).toHaveAttribute('data-canonical-operation', COMMENTS_CONTENT_HUB_LINK_OPERATION);
        expect(COMMENTS_CONTENT_HUB_LINK_OPERATION).toBe('AIMW-COMM-A0D005681B');
    });

    it('fails closed when the comments workspace permission is absent', () => {
        renderControl(context('alpha', ['tenant.view']));
        expect(screen.queryByRole('link', { name: /Content hub/i })).not.toBeInTheDocument();
    });

    it('encodes authoritative tenant context instead of accepting a cross-tenant path fragment', () => {
        renderControl(context('alpha/../beta'));

        const link = screen.getByRole('link', { name: /Content hub/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/content');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/content');
    });
});
