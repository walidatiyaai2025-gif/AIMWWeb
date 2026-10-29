import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import { SettingsAiProvidersLinkControl } from '../settings-ai-providers-link-control';
import {
    SETTINGS_LANGUAGE_ANCHOR_OPERATION_ID,
    SettingsLanguageAnchorControl,
} from '../settings-language-anchor-control';

const context = (permissions: string[]): FrontendContext => ({
    user: { id: 10, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

beforeEach(() => {
    window.localStorage.clear();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-CONT-041B5ED720 Settings language anchor', () => {
    it('renders the canonical same-page language link with a real target and no HTTP mutation', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        render(
            <LocaleProvider>
                <SettingsLanguageAnchorControl context={context(['tenant.view'])} />
                <SettingsAiProvidersLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /language and direction/i });
        expect(link).toHaveAttribute('href', '#language');
        expect(link).toHaveAttribute('aria-controls', 'language');
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_LANGUAGE_ANCHOR_OPERATION_ID);
        expect(SETTINGS_LANGUAGE_ANCHOR_OPERATION_ID).toBe('AIMW-CONT-041B5ED720');
        expect(document.getElementById('language')).not.toBeNull();
        expect(screen.getByRole('button', { name: /switch language/i })).toBeInTheDocument();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('renders Arabic navigation copy while preserving the same canonical target', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');

        render(
            <LocaleProvider>
                <SettingsLanguageAnchorControl context={context(['tenant.view'])} />
                <SettingsAiProvidersLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /اللغة والاتجاه/ });
        expect(link).toHaveAttribute('href', '#language');
        expect(document.getElementById('language')).not.toBeNull();
    });

    it('fails closed without tenant.view and exposes no canonical navigation control', () => {
        render(
            <LocaleProvider>
                <SettingsLanguageAnchorControl context={context([])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: /language and direction/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /اللغة والاتجاه/ })).not.toBeInTheDocument();
    });
});
