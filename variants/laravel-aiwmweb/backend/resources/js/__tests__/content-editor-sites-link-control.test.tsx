import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    CONTENT_EDITOR_SITES_LINK_OPERATION_ID,
    ContentEditorSitesLinkControl,
} from '../content-editor-save-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context: FrontendContext = {
    user: { id: 1, name: 'Editor', email: 'editor@example.test' },
    tenant: { slug: 'alpha team', name: 'Alpha Team' },
    tenants: [{ slug: 'alpha team', name: 'Alpha Team' }],
    permissions: ['content.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
};

describe('AIMW-CONT-B6BB292F85 Content Editor sites link', () => {
    it('binds the exact canonical operation to the tenant-scoped sites route', () => {
        render(
            <MemoryRouter>
                <LocaleProvider>
                    <ContentEditorSitesLinkControl context={context} />
                </LocaleProvider>
            </MemoryRouter>,
        );

        const link = screen.getByRole('link', { name: 'Sites' });
        expect(CONTENT_EDITOR_SITES_LINK_OPERATION_ID).toBe('AIMW-CONT-B6BB292F85');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EDITOR_SITES_LINK_OPERATION_ID);
        expect(link).toHaveAttribute('href', '/tenants/alpha%20team/sites');
    });
});
