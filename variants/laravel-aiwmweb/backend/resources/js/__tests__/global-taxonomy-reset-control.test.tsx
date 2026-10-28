import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    GlobalTaxonomyResetControl,
    GLOBAL_TAXONOMY_RESET_OPERATION_ID,
    authorizedGlobalTaxonomyResetEndpoint,
} from '../global-taxonomy-reset-control';

type ActiveContext = FrontendContext & { active_site?: { id: number } | null };

function context(overrides: Partial<ActiveContext> = {}): ActiveContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] }],
        capabilities: {},
        api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy' },
        actions: {},
        active_site: { id: 17 },
        ...overrides,
    };
}

const endpoint = '/api/v1/tenants/alpha/sites/17/taxonomy';

afterEach(() => {
    vi.restoreAllMocks();
    window.localStorage.clear();
});

describe('AIMW-SYNC-5EE3F98A28 GlobalTaxonomyExplorer ResetAsync', () => {
    it('binds reset to a real, caller-provided authoritative tenant/site re-read', async () => {
        const onResetRequested = vi.fn().mockResolvedValue(undefined);
        render(
            <LocaleProvider>
                <GlobalTaxonomyResetControl context={context()} endpoint={endpoint} busy={false} onResetRequested={onResetRequested} />
            </LocaleProvider>,
        );
        const button = screen.getByRole('button', { name: 'Reset' });
        expect(GLOBAL_TAXONOMY_RESET_OPERATION_ID).toBe('AIMW-SYNC-5EE3F98A28');
        expect(button).toHaveAttribute('data-canonical-operation', GLOBAL_TAXONOMY_RESET_OPERATION_ID);
        fireEvent.click(button);
        await waitFor(() => expect(onResetRequested).toHaveBeenCalledWith(endpoint));
        expect(onResetRequested).toHaveBeenCalledTimes(1);
    });

    it('fails closed for missing tenant/site permission, foreign tenant, or a foreign site (404)', () => {
        expect(authorizedGlobalTaxonomyResetEndpoint(context(), endpoint)).toBe(endpoint);
        for (const candidate of [
            context({ permissions: ['tenant.view', 'sites.view'] }),
            context({ active_site: null }),
            context({ active_site: { id: 18 } }),
            context({ api: { taxonomy: '/api/v1/tenants/beta/sites/17/taxonomy' } }),
            context({ tenant: { slug: 'beta', name: 'Beta' } }),
        ]) {
            expect(authorizedGlobalTaxonomyResetEndpoint(candidate, endpoint)).toBeNull();
        }
        render(
            <LocaleProvider>
                <GlobalTaxonomyResetControl context={context({ tenant: { slug: 'beta', name: 'Beta' } })} endpoint={endpoint} busy={false} onResetRequested={vi.fn()} />
            </LocaleProvider>,
        );
        expect(screen.queryByRole('button', { name: 'Reset' })).not.toBeInTheDocument();
    });

    it('prevents busy reset and reports failed re-read without fabricating success', async () => {
        const callback = vi.fn().mockRejectedValue(new Error('WordPress is unavailable'));
        const view = render(
            <LocaleProvider>
                <GlobalTaxonomyResetControl context={context()} endpoint={endpoint} busy onResetRequested={callback} />
            </LocaleProvider>,
        );
        const blocked = screen.getByRole('button', { name: 'Reset' });
        expect(blocked).toBeDisabled();
        fireEvent.click(blocked);
        expect(callback).not.toHaveBeenCalled();

        view.rerender(
            <LocaleProvider>
                <GlobalTaxonomyResetControl context={context()} endpoint={endpoint} busy={false} onResetRequested={callback} />
            </LocaleProvider>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Reset' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('WordPress is unavailable'));
        expect(callback).toHaveBeenCalledTimes(1);
        expect(screen.queryByText('Reset succeeded')).not.toBeInTheDocument();
    });

    it('preserves Arabic label on the same canonical control', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(
            <LocaleProvider>
                <GlobalTaxonomyResetControl context={context()} endpoint={endpoint} busy={false} onResetRequested={vi.fn()} />
            </LocaleProvider>,
        );
        expect(screen.getByRole('button', { name: 'إعادة ضبط' }))
            .toHaveAttribute('data-canonical-operation', GLOBAL_TAXONOMY_RESET_OPERATION_ID);
    });
});
