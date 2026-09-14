import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    QUICK_ACTIONS_TOGGLE_OPERATION,
    QuickActionsToggleControl,
} from '../quick-actions-toggle-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'content.view'],
    capabilities: Record<string, boolean> = { 'feature.content_planner': true },
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities,
    api: {},
    actions: {},
});

function renderControl(value: FrontendContext) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <QuickActionsToggleControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-PLAT-4C37AC806E Quick Actions toggle', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="topbar-actions"></div>';
    });

    afterEach(() => {
        cleanup();
        document.body.innerHTML = '';
    });

    it('renders the exact canonical toggle and truthfully opens and closes the dialog', () => {
        renderControl(context());

        const toggle = screen.getByRole('button', { name: /Open quick actions/i });
        expect(toggle).toHaveAttribute('data-canonical-operation', QUICK_ACTIONS_TOGGLE_OPERATION);
        expect(QUICK_ACTIONS_TOGGLE_OPERATION).toBe('AIMW-PLAT-4C37AC806E');
        expect(toggle).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(toggle);
        expect(screen.getByRole('dialog', { name: /Quick actions/i })).toBeInTheDocument();
        expect(toggle).toHaveAttribute('aria-expanded', 'true');

        fireEvent.click(toggle);
        expect(screen.queryByRole('dialog', { name: /Quick actions/i })).not.toBeInTheDocument();
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
    });

    it('exposes only existing permission-enabled source actions', () => {
        renderControl(context('alpha', ['tenant.view', 'content.view']));
        fireEvent.click(screen.getByRole('button', { name: /Open quick actions/i }));

        const planner = screen.getByRole('link', { name: /Content Planner/i });
        expect(planner).toHaveAttribute('href', '/tenants/alpha/content-planner');
        expect(screen.queryByRole('link', { name: /Connect Site/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /Automation Center/i })).not.toBeInTheDocument();
    });

    it('derives and encodes destinations only from the authoritative active tenant', () => {
        renderControl(context('alpha/../beta'));
        fireEvent.click(screen.getByRole('button', { name: /Open quick actions/i }));

        const planner = screen.getByRole('link', { name: /Content Planner/i });
        expect(planner).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/content-planner');
        expect(planner.getAttribute('href')).not.toBe('/tenants/beta/content-planner');
    });

    it('shows a truthful empty state instead of inventing unauthorized actions', () => {
        renderControl(context('alpha', ['tenant.view'], {}));
        fireEvent.click(screen.getByRole('button', { name: /Open quick actions/i }));

        expect(screen.getByRole('status')).toHaveTextContent(/No quick actions are available/i);
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
