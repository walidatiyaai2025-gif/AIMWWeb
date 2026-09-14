import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    QUICK_ACTIONS_CLOSE_OPERATION,
    QUICK_ACTIONS_TOGGLE_OPERATION,
    QuickActionsToggleControl,
} from '../quick-actions-toggle-control';
import type { CapabilityContract, FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (
    slug = 'alpha',
    permissions: string[] = ['tenant.view', 'content.view'],
    capabilities: Record<string, CapabilityContract> = {
        'feature.content_planner': { state: 'enabled' },
    },
): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities,
    api: {
        'content-planner': `/api/tenants/${encodeURIComponent(slug)}/content-planner`,
    },
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

describe('AIMW-PLAT-17BC7DA9E5 Quick Actions close', () => {
    beforeEach(() => {
        document.body.innerHTML = '<div class="topbar-actions"></div>';
    });

    afterEach(() => {
        cleanup();
        document.body.innerHTML = '';
    });

    it('binds the exact canonical close operation to the real dialog close control', () => {
        renderControl(context());

        const toggle = screen.getByRole('button', { name: /Open quick actions/i });
        expect(toggle).toHaveAttribute('data-canonical-operation', QUICK_ACTIONS_TOGGLE_OPERATION);
        fireEvent.click(toggle);

        const close = screen.getByRole('button', { name: /Close quick actions/i });
        expect(QUICK_ACTIONS_CLOSE_OPERATION).toBe('AIMW-PLAT-17BC7DA9E5');
        expect(close).toHaveAttribute('data-canonical-operation', QUICK_ACTIONS_CLOSE_OPERATION);
        expect(document.querySelectorAll(`[data-canonical-operation="${QUICK_ACTIONS_CLOSE_OPERATION}"]`)).toHaveLength(1);
    });

    it('closes locally without navigation or mutation and restores focus to the trigger', async () => {
        renderControl(context('alpha/../beta'));

        const toggle = screen.getByRole('button', { name: /Open quick actions/i });
        fireEvent.click(toggle);
        expect(screen.getByRole('dialog', { name: /Quick actions/i })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Close quick actions/i }));

        expect(screen.queryByRole('dialog', { name: /Quick actions/i })).not.toBeInTheDocument();
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(window.location.pathname).toBe('/');
        await waitFor(() => expect(toggle).toHaveFocus());
    });

    it('keeps Escape and backdrop dismissal working without falsely marking them as the canonical close control', async () => {
        renderControl(context());

        const toggle = screen.getByRole('button', { name: /Open quick actions/i });
        fireEvent.click(toggle);
        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog', { name: /Quick actions/i })).not.toBeInTheDocument();
        await waitFor(() => expect(toggle).toHaveFocus());

        fireEvent.click(toggle);
        const backdrop = document.querySelector<HTMLElement>('.dialog-backdrop');
        expect(backdrop).not.toBeNull();
        expect(backdrop).not.toHaveAttribute('data-canonical-operation');
        fireEvent.mouseDown(backdrop!);
        expect(screen.queryByRole('dialog', { name: /Quick actions/i })).not.toBeInTheDocument();
        await waitFor(() => expect(toggle).toHaveFocus());
    });
});
