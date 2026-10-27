import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    authoritativeGlobalMediaEndpoint,
    GLOBAL_MEDIA_RESET_OPERATION_ID,
    globalMediaResetState,
    GlobalMediaResetControl,
} from '../global-media-reset-control';

type SiteAwareContext = FrontendContext & {
    active_site?: { id: number; name?: string; status?: string } | null;
};

function context(overrides: Partial<SiteAwareContext> = {}): SiteAwareContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'content.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['media.read'] }],
        capabilities: {},
        api: { media: '/api/v1/tenants/alpha/sites/7/media' },
        actions: {},
        active_site: { id: 7, name: 'Alpha Site', status: 'active' },
        ...overrides,
    };
}

describe('AIMW-SYNC-9B87CACA17 GlobalMediaExplorer ResetAsync', () => {
    it('preserves the exact source reset defaults', () => {
        expect(globalMediaResetState()).toEqual({
            search: '',
            mediaType: 'all',
            pageSize: 24,
            page: 1,
        });
        expect(GLOBAL_MEDIA_RESET_OPERATION_ID).toBe('AIMW-SYNC-9B87CACA17');
    });

    it('binds the reset action to the canonical operation and prevents duplicate reset while busy', () => {
        const onReset = vi.fn();
        const { rerender } = render(
            <GlobalMediaResetControl locale="en" busy={false} available onReset={onReset} />,
        );

        const button = screen.getByRole('button', { name: 'Reset' });
        expect(button).toHaveAttribute('data-canonical-operation', GLOBAL_MEDIA_RESET_OPERATION_ID);
        fireEvent.click(button);
        expect(onReset).toHaveBeenCalledTimes(1);

        rerender(<GlobalMediaResetControl locale="en" busy available onReset={onReset} />);
        expect(screen.getByRole('button', { name: 'Resetting…' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Resetting…' }));
        expect(onReset).toHaveBeenCalledTimes(1);
    });

    it('accepts only the exact active tenant/site media endpoint and otherwise fails closed', () => {
        expect(authoritativeGlobalMediaEndpoint(context())).toBe('/api/v1/tenants/alpha/sites/7/media');
        expect(authoritativeGlobalMediaEndpoint(context({
            api: { media: '/api/v1/tenants/beta/sites/7/media' },
        }))).toBeNull();
        expect(authoritativeGlobalMediaEndpoint(context({
            api: { media: '/api/v1/tenants/alpha/sites/99/media' },
        }))).toBeNull();
        expect(authoritativeGlobalMediaEndpoint(context({
            permissions: ['tenant.view'],
        }))).toBeNull();
        expect(authoritativeGlobalMediaEndpoint(context({
            active_site: null,
        }))).toBeNull();
    });

    it('renders nothing when the authoritative runtime contract is unavailable', () => {
        render(<GlobalMediaResetControl locale="en" busy={false} available={false} onReset={vi.fn()} />);
        expect(screen.queryByRole('button', { name: 'Reset' })).toBeNull();
    });
});
