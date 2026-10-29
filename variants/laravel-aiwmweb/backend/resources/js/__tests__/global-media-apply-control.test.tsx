import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    authoritativeGlobalMediaApplyEndpoint,
    defaultGlobalMediaFilters,
    GLOBAL_MEDIA_APPLY_OPERATION_ID,
    globalMediaApplyQuery,
    GlobalMediaApplyControl,
} from '../global-media-apply-control';

type SiteContext = FrontendContext & { active_site?: { id: number } | null };

const context = (overrides: Partial<SiteContext> = {}): SiteContext => ({
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view', 'content.view'],
    connectors: [{ key: 'wordpress', state: 'connected', scopes: ['media.read'] }],
    capabilities: {},
    api: { media: '/api/v1/tenants/alpha/sites/7/media' },
    actions: {},
    active_site: { id: 7 },
    ...overrides,
});

describe('AIMW-SYNC-D805D5D9A8 GlobalMediaExplorer ApplyAsync', () => {
    it('preserves source media type and page-size choices with page-one read parameters', () => {
        expect(defaultGlobalMediaFilters()).toEqual({ mediaType: 'all', pageSize: 24 });
        expect(globalMediaApplyQuery({ mediaType: 'image', pageSize: 12 }))
            .toEqual({ mime_type: 'image', per_page: '12' });
        expect(globalMediaApplyQuery({ mediaType: 'all', pageSize: 96 }))
            .toEqual({ per_page: '96' });
    });

    it('renders the real submit control, allowing draft edits without prematurely applying them', () => {
        const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());
        const onTypeChange = vi.fn();
        const onPageSizeChange = vi.fn();
        render(
            <form onSubmit={onSubmit}>
                <GlobalMediaApplyControl
                    locale="en"
                    value={defaultGlobalMediaFilters()}
                    busy={false}
                    available
                    onTypeChange={onTypeChange}
                    onPageSizeChange={onPageSizeChange}
                />
            </form>,
        );
        fireEvent.change(screen.getByLabelText('Media type'), { target: { value: 'video' } });
        fireEvent.change(screen.getByLabelText('Page size'), { target: { value: '48' } });
        expect(onTypeChange).toHaveBeenCalledWith('video');
        expect(onPageSizeChange).toHaveBeenCalledWith(48);
        const apply = screen.getByRole('button', { name: 'Apply filters' });
        expect(apply).toHaveAttribute('data-canonical-operation', GLOBAL_MEDIA_APPLY_OPERATION_ID);
        fireEvent.click(apply);
        expect(onSubmit).toHaveBeenCalledTimes(1);
    });

    it('disables submission during the authoritative read and hides it when unavailable', () => {
        const props = {
            locale: 'en' as const,
            value: defaultGlobalMediaFilters(),
            onTypeChange: vi.fn(),
            onPageSizeChange: vi.fn(),
        };
        const { rerender } = render(<GlobalMediaApplyControl {...props} busy available />);
        expect(screen.getByRole('button', { name: 'Applying filters…' })).toBeDisabled();
        rerender(<GlobalMediaApplyControl {...props} busy={false} available={false} />);
        expect(screen.queryByRole('button', { name: 'Apply filters' })).toBeNull();
    });

    it('accepts only the trusted tenant and active site endpoint', () => {
        expect(authoritativeGlobalMediaApplyEndpoint(context()))
            .toBe('/api/v1/tenants/alpha/sites/7/media');
        expect(authoritativeGlobalMediaApplyEndpoint(context({ api: { media: '/api/v1/tenants/beta/sites/7/media' } }))).toBeNull();
        expect(authoritativeGlobalMediaApplyEndpoint(context({ api: { media: '/api/v1/tenants/alpha/sites/99/media' } }))).toBeNull();
        expect(authoritativeGlobalMediaApplyEndpoint(context({ api: { media: 'https://attacker.test/other' } }))).toBeNull();
        expect(authoritativeGlobalMediaApplyEndpoint(context({ api: { media: '/api/v1/tenants/alpha/sites/7/media?tenant=beta' } }))).toBeNull();
        expect(authoritativeGlobalMediaApplyEndpoint(context({ active_site: null }))).toBeNull();
        expect(authoritativeGlobalMediaApplyEndpoint(context({ permissions: ['tenant.view'] }))).toBeNull();
    });
});
