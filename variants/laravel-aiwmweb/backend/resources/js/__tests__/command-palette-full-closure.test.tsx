import React from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AppShell } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const OPERATION_ID = 'AIMW-AI-2C653A870A';

function contextForAlpha(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha Tenant' },
        tenants: [{ slug: 'alpha', name: 'Alpha Tenant' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: {
            sites: '/api/tenants/alpha/sites',
        },
        actions: {},
    };
}

function LocationProbe() {
    return <output data-testid="location">{useLocation().pathname}</output>;
}

function renderShell(context: FrontendContext, initialEntry = '/tenants/beta') {
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={[initialEntry]}>
                <AppShell context={context}>
                    <LocationProbe />
                </AppShell>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

describe(`${OPERATION_ID} OpenCommandPalette full closure`, () => {
    it('opens the real dialog and exposes only destinations enabled by the authoritative tenant capability context', () => {
        const context = contextForAlpha();
        renderShell(context);

        const trigger = screen.getByRole('button', { name: 'Open quick search' });
        expect(trigger).toHaveAttribute('aria-controls', 'command-palette-dialog');
        expect(trigger).toHaveAttribute('aria-expanded', 'false');

        fireEvent.click(trigger);

        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        const dialog = screen.getByRole('dialog', { name: 'Quick search' });
        const input = within(dialog).getByRole('textbox', { name: 'Quick search' });

        fireEvent.change(input, { target: { value: 'Posts' } });
        expect(within(dialog).queryByRole('button', { name: /Posts/i })).not.toBeInTheDocument();
        expect(within(dialog).getByRole('status')).toHaveTextContent('No matching available destination');

        fireEvent.change(input, { target: { value: 'Sites' } });
        expect(within(dialog).getByRole('button', { name: /Sites/i })).toBeInTheDocument();
    });

    it('derives navigation from context.tenant.slug rather than the caller URL tenant and rechecks capability before navigating', () => {
        const context = contextForAlpha();
        renderShell(context, '/tenants/beta');

        fireEvent.click(screen.getByRole('button', { name: 'Open quick search' }));
        const dialog = screen.getByRole('dialog', { name: 'Quick search' });
        const sites = within(dialog).getByRole('button', { name: /Sites/i });

        context.permissions = ['tenant.view'];
        fireEvent.click(sites);

        expect(screen.getByTestId('location')).toHaveTextContent('/tenants/beta');
        expect(screen.getByRole('dialog', { name: 'Quick search' })).toBeInTheDocument();

        context.permissions = ['tenant.view', 'sites.view'];
        fireEvent.click(sites);

        expect(screen.getByTestId('location')).toHaveTextContent('/tenants/alpha/sites');
        expect(screen.queryByRole('dialog', { name: 'Quick search' })).not.toBeInTheDocument();
    });

    it('supports the canonical Ctrl+K open path and resets stale search text on each new open', () => {
        renderShell(contextForAlpha());

        fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
        let dialog = screen.getByRole('dialog', { name: 'Quick search' });
        const input = within(dialog).getByRole('textbox', { name: 'Quick search' });
        fireEvent.change(input, { target: { value: 'sites' } });
        expect(input).toHaveValue('sites');

        fireEvent.click(within(dialog).getByRole('button', { name: 'Esc' }));
        expect(screen.queryByRole('dialog', { name: 'Quick search' })).not.toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'k', ctrlKey: true });
        dialog = screen.getByRole('dialog', { name: 'Quick search' });
        expect(within(dialog).getByRole('textbox', { name: 'Quick search' })).toHaveValue('');
    });
});
