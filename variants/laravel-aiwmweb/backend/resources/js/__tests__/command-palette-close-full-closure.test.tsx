import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { AppShell } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const OPERATION_ID = 'AIMW-AI-D3A8A100B4';

function contextForAlpha(): FrontendContext {
    return {
        user: { id: 1, name: 'Alpha User', email: 'alpha@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha Tenant' },
        tenants: [{ slug: 'alpha', name: 'Alpha Tenant' }],
        permissions: ['tenant.view', 'sites.view'],
        connectors: [],
        capabilities: {},
        api: { sites: '/api/tenants/alpha/sites' },
        actions: {},
    };
}

function LocationProbe() {
    return <output data-testid="location">{useLocation().pathname}</output>;
}

function renderShell(initialEntry = '/tenants/alpha') {
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={[initialEntry]}>
                <AppShell context={contextForAlpha()}>
                    <LocationProbe />
                </AppShell>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

async function expectClosedAndFocusRestored(trigger: HTMLElement) {
    expect(screen.queryByRole('dialog', { name: 'Quick search' })).not.toBeInTheDocument();
    await waitFor(() => expect(trigger).toHaveFocus());
    expect(screen.getByTestId('location')).toHaveTextContent('/tenants/alpha');
}

describe(`${OPERATION_ID} CloseCommandPalette full closure`, () => {
    it('binds the exact canonical close control and closes without navigation or mutation', async () => {
        renderShell();
        const trigger = screen.getByRole('button', { name: 'Open quick search' });
        fireEvent.click(trigger);

        const dialog = screen.getByRole('dialog', { name: 'Quick search' });
        const close = within(dialog).getByRole('button', { name: 'Close search' });
        expect(close).toHaveAttribute('data-canonical-operation', OPERATION_ID);
        expect(close).toHaveTextContent('Esc');

        fireEvent.click(close);
        await expectClosedAndFocusRestored(trigger);
    });

    it('implements the source Escape close contract and restores focus to the real trigger', async () => {
        renderShell();
        const trigger = screen.getByRole('button', { name: 'Open quick search' });
        fireEvent.click(trigger);
        expect(screen.getByRole('dialog', { name: 'Quick search' })).toBeInTheDocument();

        const notCancelled = fireEvent.keyDown(document, { key: 'Escape' });
        expect(notCancelled).toBe(false);
        await expectClosedAndFocusRestored(trigger);
    });

    it('closes from the real backdrop and restores focus without changing tenant route state', async () => {
        const { container } = renderShell();
        const trigger = screen.getByRole('button', { name: 'Open quick search' });
        fireEvent.click(trigger);

        const backdrop = container.querySelector('.command-backdrop');
        expect(backdrop).not.toBeNull();
        fireEvent.mouseDown(backdrop!);

        await expectClosedAndFocusRestored(trigger);
    });
});
