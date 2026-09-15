import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_AI_PROVIDERS_LINK_OPERATION_ID,
    SettingsAiProvidersLinkControl,
} from '../settings-ai-providers-link-control';

const context = (permissions: string[], slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('AIMW-AI-8205320842 Settings AI providers link', () => {
    it('renders the canonical tenant-scoped destination for settings managers', () => {
        render(<LocaleProvider><SettingsAiProvidersLinkControl context={context(['settings.manage'])} /></LocaleProvider>);

        const link = screen.getByRole('link', { name: /AI providers/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/settings/ai-providers');
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_AI_PROVIDERS_LINK_OPERATION_ID);
        expect(SETTINGS_AI_PROVIDERS_LINK_OPERATION_ID).toBe('AIMW-AI-8205320842');
    });

    it('hides the administrator-only destination without settings.manage', () => {
        render(<LocaleProvider><SettingsAiProvidersLinkControl context={context(['tenant.view'])} /></LocaleProvider>);
        expect(screen.queryByRole('link', { name: /AI providers/i })).not.toBeInTheDocument();
    });

    it('encodes the authoritative tenant slug rather than accepting a cross-tenant fragment', () => {
        render(<LocaleProvider><SettingsAiProvidersLinkControl context={context(['settings.manage'], 'alpha/../beta')} /></LocaleProvider>);
        expect(screen.getByRole('link', { name: /AI providers/i }))
            .toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/settings/ai-providers');
    });
});
