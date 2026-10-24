import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    CONTENT_EXPLORER_PREVIEW_LINK_OPERATION_ID,
    ContentExplorerPreviewPanel,
} from '../content-explorer-preview-panel';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name: string; status: string } | null;
};

const previewContext: SiteAwareContext = {
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'sites.view', 'content.view'],
    connectors: [],
    capabilities: {},
    api: { explorer: '/api/v1/tenants/alpha/sites/17/explorer' },
    actions: {},
    active_site: { id: 17, name: 'Alpha Site', status: 'active' },
};

function renderPreview(link: unknown, onClose = vi.fn()) {
    return render(
        <LocaleProvider>
            <ContentExplorerPreviewPanel
                context={previewContext}
                row={{
                    id: 7,
                    wordpress_id: 71,
                    type: 'post',
                    title: 'Authoritative post',
                    slug: 'authoritative-post',
                    status: 'publish',
                    modified_at: '2026-10-24T10:00:00Z',
                    link,
                }}
                onClose={onClose}
            />
        </LocaleProvider>,
    );
}

describe('AIMW-CONT-37B4B4BEC4 Content Explorer preview link', () => {
    it('renders the persisted preview link with safe new-tab semantics', () => {
        renderPreview('https://alpha.example.test/authoritative-post');

        const link = screen.getByRole('link', { name: /Open on site/ });
        expect(link).toHaveAttribute('href', 'https://alpha.example.test/authoritative-post');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('data-canonical-operation', CONTENT_EXPLORER_PREVIEW_LINK_OPERATION_ID);
        expect(CONTENT_EXPLORER_PREVIEW_LINK_OPERATION_ID).toBe('AIMW-CONT-37B4B4BEC4');
        expect(screen.getByText('Authoritative post')).toBeInTheDocument();
        expect(screen.getByText('/authoritative-post')).toBeInTheDocument();
    });

    it('fails closed for an unsafe or unavailable preview link', () => {
        const { rerender } = renderPreview('javascript:alert(1)');
        expect(screen.queryByRole('link', { name: /Open on site/ })).not.toBeInTheDocument();
        expect(screen.getByText('Site link is unavailable.')).toBeInTheDocument();

        rerender(
            <LocaleProvider>
                <ContentExplorerPreviewPanel context={previewContext} row={{ title: 'No link' }} onClose={() => undefined} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('link', { name: /Open on site/ })).not.toBeInTheDocument();
    });

    it('closes the preview without a network or mutation side effect', () => {
        const onClose = vi.fn();
        renderPreview('https://alpha.example.test/post', onClose);

        fireEvent.click(screen.getByRole('button', { name: 'Close preview' }));
        expect(onClose).toHaveBeenCalledTimes(1);
    });
});

// exact-head gate retrigger after parity materialization
