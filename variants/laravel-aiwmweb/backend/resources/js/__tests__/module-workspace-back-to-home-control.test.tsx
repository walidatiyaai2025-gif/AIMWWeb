import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    authoritativeModuleWorkspaceBackToHomeHref,
    MODULE_WORKSPACE_BACK_TO_HOME_OPERATION_ID,
    ModuleWorkspaceBackToHomeControl,
} from '../module-workspace-back-to-home-control';

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view'],
        connectors: [],
        capabilities: {},
        api: {},
        actions: {},
        ...overrides,
    };
}

function renderControl(value = context()) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <ModuleWorkspaceBackToHomeControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe(`${MODULE_WORKSPACE_BACK_TO_HOME_OPERATION_ID} ModuleWorkspace back-to-home control`, () => {
    it('renders the source-equivalent unavailable state and tenant-scoped home destination', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl();

        expect(screen.getByTestId('module-unavailable')).toHaveAttribute(
            'data-canonical-operation',
            MODULE_WORKSPACE_BACK_TO_HOME_OPERATION_ID,
        );
        expect(screen.getByRole('heading', { name: 'This capability is not available yet' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to home' })).toHaveAttribute('href', '/tenants/alpha');
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('derives the destination only from authenticated tenant context and encodes the slug', () => {
        expect(authoritativeModuleWorkspaceBackToHomeHref(context({
            tenant: { slug: 'tenant with space', name: 'Encoded tenant' },
        }))).toBe('/tenants/tenant%20with%20space');
    });

    it('fails closed without tenant visibility and performs no request', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        renderControl(context({ permissions: [] }));

        expect(screen.queryByTestId('module-unavailable')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Back to home' })).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
