import React from 'react';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    MODULE_WORKSPACE_ROUTE_OPERATION_ID,
    ModuleWorkspaceRouteFallback,
} from '../module-workspace-route-fallback';

const context: FrontendContext = {
    user: { id: 1, name: 'Alpha Owner', email: 'owner@alpha.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions: ['tenant.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
};

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('AIMW-CONT-80EE7CA296 generic module workspace route fallback', () => {
    it('renders an honest unavailable state without issuing a request', () => {
        const fetchSpy = vi.spyOn(globalThis, 'fetch');

        render(
            <LocaleProvider>
                <MemoryRouter>
                    <ModuleWorkspaceRouteFallback context={context} moduleKey="future-capability" />
                </MemoryRouter>
            </LocaleProvider>,
        );

        const workspace = screen.getByTestId('module-workspace-fallback');
        expect(workspace).toHaveAttribute('data-canonical-operation', MODULE_WORKSPACE_ROUTE_OPERATION_ID);
        expect(screen.getByTestId('module-unavailable')).toHaveTextContent('This capability is not available yet');
        expect(screen.getByRole('link', { name: 'Back to home' })).toHaveAttribute('href', '/tenants/alpha');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('normalizes the module key only for presentation and does not fabricate a destination', () => {
        render(
            <LocaleProvider>
                <MemoryRouter>
                    <ModuleWorkspaceRouteFallback context={context} moduleKey=" Future-Capability " />
                </MemoryRouter>
            </LocaleProvider>,
        );

        expect(screen.getByTestId('module-workspace-fallback')).toHaveClass('module-future-capability');
        expect(screen.queryByRole('link', { name: /open real workspace/i })).not.toBeInTheDocument();
    });
});
