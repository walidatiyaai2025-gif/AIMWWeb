import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it } from 'vitest';
import { AppShell } from '../components';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { MAIN_LAYOUT_OPERATION_IDS, MainLayoutParityControls } from '../main-layout-parity-controls';

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

function renderControls(initialEntry = '/tenants/alpha/sites') {
    const context = contextForAlpha();
    return render(
        <LocaleProvider>
            <MemoryRouter initialEntries={[initialEntry]}>
                <AppShell context={context}>
                    <MainLayoutParityControls context={context} />
                    <p>Tenant content</p>
                </AppShell>
            </MemoryRouter>
        </LocaleProvider>,
    );
}

beforeEach(() => {
    window.localStorage.clear();
    delete document.documentElement.dataset.theme;
});

describe('MainLayout canonical local controls', () => {
    it('marks existing skip/home/sidebar/command/appearance/language controls and publishes a tenant-qualified About Build link', async () => {
        renderControls();

        await waitFor(() => expect(document.querySelector('.skip-link')).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.skipToContent));
        expect(document.querySelector('.brand')).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.home);
        expect(document.querySelector('.sidebar-toggle')).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.toggleSidebar);
        expect(document.querySelector('#command-palette-trigger')).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.openCommandPalette);
        expect(document.querySelector('.language-switch')).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.switchLanguage);
        expect(document.querySelector('.topbar-actions > button.icon-button')).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.toggleAppearance);

        const build = screen.getByRole('link', { name: 'Build information' });
        expect(build).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.aboutBuild);
        expect(build).toHaveAttribute('href', '/tenants/alpha/about-build');
    });

    it('opens, applies, persists and closes the canonical eight-color Theme Picker', async () => {
        renderControls();

        const trigger = await screen.findByRole('button', { name: 'Change application colors' });
        expect(trigger).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.toggleThemePicker);
        fireEvent.click(trigger);

        let dialog = screen.getByRole('dialog', { name: 'Application colors' });
        const close = within(dialog).getByRole('button', { name: 'Close color picker' });
        expect(close).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.closeThemePicker);
        expect(within(dialog).getAllByRole('button', { pressed: false })).toHaveLength(7);

        fireEvent.click(within(dialog).getByRole('button', { name: 'Ocean' }));
        expect(screen.queryByRole('dialog', { name: 'Application colors' })).not.toBeInTheDocument();
        expect(document.documentElement.dataset.theme).toBe('ocean');
        expect(window.localStorage.getItem('aiwm-color-theme')).toBe('ocean');

        fireEvent.click(trigger);
        dialog = screen.getByRole('dialog', { name: 'Application colors' });
        expect(within(dialog).getByRole('button', { name: 'Ocean' })).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(within(dialog).getByRole('button', { name: 'Close color picker' }));
        expect(screen.queryByRole('dialog', { name: 'Application colors' })).not.toBeInTheDocument();
    });

    it('tracks only available tenant routes, opens recent pages by control and shortcut, and persists favorites', async () => {
        renderControls();

        const trigger = await screen.findByRole('button', { name: 'Open favorites and recent pages' });
        expect(trigger).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.openRecentPages);
        fireEvent.click(trigger);

        let dialog = screen.getByRole('dialog', { name: 'Favorites and recent pages' });
        const sitesLink = within(dialog).getByRole('link', { name: /Sites/i });
        expect(sitesLink).toHaveAttribute('href', '/tenants/alpha/sites');
        expect(within(dialog).queryByRole('link', { name: /Posts/i })).not.toBeInTheDocument();

        fireEvent.click(within(dialog).getByRole('button', { name: 'Add Sites to favorites' }));
        expect(JSON.parse(window.localStorage.getItem('aiwm-favorite-pages') ?? '[]')).toContain('/sites');

        fireEvent.click(within(dialog).getByRole('button', { name: 'Close quick access' }));
        fireEvent.keyDown(document, { key: 'p', ctrlKey: true, shiftKey: true });
        dialog = screen.getByRole('dialog', { name: 'Favorites and recent pages' });
        expect(within(dialog).getByText('Favorites')).toBeInTheDocument();
    });

    it('moves from the real Command Palette to recent/favorites without leaving the command dialog open', async () => {
        renderControls();

        fireEvent.click(screen.getByRole('button', { name: 'Open quick search' }));
        const command = screen.getByRole('dialog', { name: 'Quick search' });
        const recentFromCommand = await within(command).findByRole('button', { name: /Favorites & recent/i });
        expect(recentFromCommand).toHaveAttribute('data-canonical-operation', MAIN_LAYOUT_OPERATION_IDS.openRecentFromCommand);

        fireEvent.click(recentFromCommand);

        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Quick search' })).not.toBeInTheDocument());
        expect(screen.getByRole('dialog', { name: 'Favorites and recent pages' })).toBeInTheDocument();
    });
});
