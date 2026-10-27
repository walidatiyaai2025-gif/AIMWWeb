import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { CONTENT_HUB_ALL_SITES_OPERATION_ID, ContentHubAllSitesControl } from '../content-hub-all-sites-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (slug = 'alpha', permissions = ['tenant.view', 'content.view']): FrontendContext => ({
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

const renderControl = (entry: string, value = context()) => render(
    <MemoryRouter initialEntries={[entry]}>
        <LocaleProvider>
            <ContentHubAllSitesControl context={value} />
        </LocaleProvider>
    </MemoryRouter>,
);

describe('AIMW-CONT-4D6C928D75 content hub All sites control', () => {
    it('clears validated site context back to the tenant content hub', () => {
        renderControl('/tenants/alpha/content?site=17');
        const link = screen.getByRole('link', { name: 'All sites' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/content');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_HUB_ALL_SITES_OPERATION_ID);
    });

    it('is absent without site context or content permission', () => {
        renderControl('/tenants/alpha/content');
        expect(screen.queryByRole('link', { name: 'All sites' })).not.toBeInTheDocument();
    });

    it('rejects unvalidated site selectors and encodes tenant identity', () => {
        renderControl('/tenants/alpha/content?site=../99', context('alpha/../beta'));
        expect(screen.queryByRole('link', { name: 'All sites' })).not.toBeInTheDocument();
    });
});
