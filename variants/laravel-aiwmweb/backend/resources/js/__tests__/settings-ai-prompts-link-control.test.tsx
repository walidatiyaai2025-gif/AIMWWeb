import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    SETTINGS_AI_PROMPTS_LINK_OPERATION_ID,
    SettingsAiPromptsLinkControl,
} from '../settings-ai-prompts-link-control';

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

describe('AIMW-AI-0D4D60320B Settings AI prompt templates link', () => {
    it('renders the canonical tenant-scoped prompt templates destination for settings managers', () => {
        render(
            <LocaleProvider>
                <SettingsAiPromptsLinkControl context={context(['tenant.view', 'settings.manage'])} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /AI prompt templates/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/settings/ai-prompts');
        expect(link).toHaveAttribute('data-canonical-operation', SETTINGS_AI_PROMPTS_LINK_OPERATION_ID);
        expect(SETTINGS_AI_PROMPTS_LINK_OPERATION_ID).toBe('AIMW-AI-0D4D60320B');
    });

    it('does not expose the administrator-only destination without settings.manage', () => {
        render(
            <LocaleProvider>
                <SettingsAiPromptsLinkControl context={context(['tenant.view'])} />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('link', { name: /AI prompt templates/i })).not.toBeInTheDocument();
    });

    it('encodes the authoritative tenant slug instead of accepting a cross-tenant path fragment', () => {
        render(
            <LocaleProvider>
                <SettingsAiPromptsLinkControl context={context(['tenant.view', 'settings.manage'], 'alpha/../beta')} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /AI prompt templates/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha%2F..%2Fbeta/settings/ai-prompts');
        expect(link.getAttribute('href')).not.toBe('/tenants/beta/settings/ai-prompts');
    });
});
