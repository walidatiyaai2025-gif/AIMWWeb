import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    GLOBAL_TAXONOMY_APPLY_OPERATION_ID,
    defaultGlobalTaxonomyApplyFilters,
    globalTaxonomyApplyQuery,
    GlobalTaxonomyApplyControl,
} from '../global-taxonomy-apply-control';

type ActiveContext = FrontendContext & { active_site?: { id: number } | null };
const endpoint = '/api/v1/tenants/alpha/sites/17/taxonomy';
function context(overrides: Partial<ActiveContext> = {}): ActiveContext {
    return {
        user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] }],
        capabilities: {}, api: { taxonomy: endpoint }, actions: {},
        active_site: { id: 17 }, ...overrides,
    };
}
afterEach(() => { vi.restoreAllMocks(); window.localStorage.clear(); });

describe('AIMW-SYNC-B955324F04 GlobalTaxonomyExplorer ApplyAsync', () => {
    it('exposes exact source default/minimum usage/page size filters for server read', () => {
        expect(GLOBAL_TAXONOMY_APPLY_OPERATION_ID).toBe('AIMW-SYNC-B955324F04');
        expect(defaultGlobalTaxonomyApplyFilters()).toEqual({ minimumUsage: 0, pageSize: 25 });
        expect(globalTaxonomyApplyQuery({ minimumUsage: 5, pageSize: 50 }))
            .toEqual({ minimum_usage: '5', per_page: '50' });
    });

    it('only sends Apply to the owned tenant/site and preserves draft filters until Apply', async () => {
        const onChange = vi.fn();
        const onApplyRequested = vi.fn().mockResolvedValue(undefined);
        render(<LocaleProvider>
            <GlobalTaxonomyApplyControl context={context()} endpoint={endpoint}
                value={defaultGlobalTaxonomyApplyFilters()} onChange={onChange}
                busy={false} onApplyRequested={onApplyRequested} />
        </LocaleProvider>);
        fireEvent.change(screen.getByLabelText('Minimum usage'), { target: { value: '10' } });
        expect(onChange).toHaveBeenCalledWith({ minimumUsage: 10, pageSize: 25 });
        fireEvent.change(screen.getByLabelText('Page size'), { target: { value: '100' } });
        expect(onChange).toHaveBeenCalledWith({ minimumUsage: 0, pageSize: 100 });
        const button = screen.getByRole('button', { name: 'Apply filters' });
        expect(button).toHaveAttribute('data-canonical-operation', GLOBAL_TAXONOMY_APPLY_OPERATION_ID);
        fireEvent.click(button);
        await waitFor(() => expect(onApplyRequested).toHaveBeenCalledWith(endpoint));
        expect(onApplyRequested).toHaveBeenCalledTimes(1);
    });

    it('prevents 403 permissions and 404 foreign tenant or site before any API read', () => {
        const onApplyRequested = vi.fn();
        for (const overrides of [
            { permissions: ['tenant.view', 'sites.view'] },
            { active_site: null },
            { active_site: { id: 99 } },
            { tenant: { slug: 'other', name: 'Other' } },
            { api: { taxonomy: '/api/v1/tenants/beta/sites/17/taxonomy' } },
        ] as Partial<ActiveContext>[]) {
            const view = render(<LocaleProvider><GlobalTaxonomyApplyControl
                context={context(overrides)} endpoint={endpoint}
                value={defaultGlobalTaxonomyApplyFilters()} onChange={vi.fn()}
                busy={false} onApplyRequested={onApplyRequested} /></LocaleProvider>);
            expect(view.queryByRole('button', { name: 'Apply filters' })).toBeNull();
            view.unmount();
        }
        expect(onApplyRequested).not.toHaveBeenCalled();
    });

    it('blocks concurrent reloads and reports rejected authoritative GET honestly', async () => {
        const onApplyRequested = vi.fn().mockRejectedValue(new Error('WordPress read unavailable'));
        const props = { context: context(), endpoint, value: defaultGlobalTaxonomyApplyFilters(),
            onChange: vi.fn(), onApplyRequested };
        const view = render(<LocaleProvider><GlobalTaxonomyApplyControl {...props} busy /></LocaleProvider>);
        expect(screen.getByRole('button', { name: 'Applying filters…' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button'));
        expect(onApplyRequested).not.toHaveBeenCalled();
        view.rerender(<LocaleProvider><GlobalTaxonomyApplyControl {...props} busy={false} /></LocaleProvider>);
        fireEvent.click(screen.getByRole('button', { name: 'Apply filters' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('WordPress read unavailable'));
        expect(onApplyRequested).toHaveBeenCalledTimes(1);
    });

    it('renders Arabic controls with the same canonical operation', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(<LocaleProvider><GlobalTaxonomyApplyControl context={context()} endpoint={endpoint}
            value={defaultGlobalTaxonomyApplyFilters()} onChange={vi.fn()} busy={false}
            onApplyRequested={vi.fn()} /></LocaleProvider>);
        expect(screen.getByRole('button', { name: 'تطبيق الفلاتر' }))
            .toHaveAttribute('data-canonical-operation', GLOBAL_TAXONOMY_APPLY_OPERATION_ID);
    });
});
