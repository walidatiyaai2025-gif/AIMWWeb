import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    AI_WORKSPACE_CARD_OPERATION_ID,
    AI_WORKSPACE_OPERATION_ID,
    AiWorkspaceHub,
} from '../ai-workspace-hub';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const allPermissions = ['tenant.view', 'ai.use', 'settings.manage', 'approvals.view'];

const context = (slug = 'alpha', permissions = allPermissions): FrontendContext => ({
    user: { id: 10, name: 'Alpha Owner', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

function renderHub(slug = 'alpha', permissions = allPermissions) {
    return render(
        <LocaleProvider>
            <AiWorkspaceHub context={context(slug, permissions)} />
        </LocaleProvider>,
    );
}

describe('AIMW-AI-8EE4F9F6FC AI workspace route', () => {
    it('renders the canonical read-only AI workspace heading and real-workspaces notice', () => {
        const { container } = renderHub();

        expect(screen.getByRole('heading', { level: 1, name: 'AI Workspace' })).toBeInTheDocument();
        expect(screen.getByText('Manage the AI center, providers, and prompt templates in their real workspaces.')).toBeInTheDocument();
        expect(screen.getByText('Real workspaces only')).toBeInTheDocument();
        expect(screen.getByText('Every card below opens an implemented workspace. Static readiness badges and non-actionable prototype cards have been removed.')).toBeInTheDocument();
        expect(container.querySelector('[data-testid="ai-workspace-hub"]')).toHaveAttribute('data-canonical-operation', AI_WORKSPACE_OPERATION_ID);
        expect(AI_WORKSPACE_OPERATION_ID).toBe('AIMW-AI-8EE4F9F6FC');
    });

    it('renders exactly the four canonical cards in source order with tenant-qualified destinations for a fully authorized member', () => {
        renderHub();

        const links = screen.getAllByTestId('workspace-link');
        expect(links).toHaveLength(4);
        expect(links.map((link) => link.getAttribute('data-workspace-key'))).toEqual([
            'ai-center',
            'ai-providers',
            'ai-prompts',
            'approvals',
        ]);
        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/tenants/alpha/ai-center',
            '/tenants/alpha/settings/ai-providers',
            '/tenants/alpha/settings/ai-prompts',
            '/tenants/alpha/approvals',
        ]);
        expect(screen.getByRole('link', { name: /Select Site/i })).toHaveAttribute('href', '/tenants/alpha/sites');
    });

    it('preserves the source card labels and descriptions while keeping every authorized canonical card actionable', () => {
        renderHub();

        expect(screen.getByRole('heading', { level: 3, name: 'AI Center' })).toBeInTheDocument();
        expect(screen.getByText('Open the operational AI workspace.')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 3, name: 'AI Providers' })).toBeInTheDocument();
        expect(screen.getByText('Manage provider connections, keys, and models.')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 3, name: 'Prompt Templates' })).toBeInTheDocument();
        expect(screen.getByText('Manage persisted templates through the real prompt store.')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 3, name: 'Approvals' })).toBeInTheDocument();
        expect(screen.getByText('Review execution requests that require approval.')).toBeInTheDocument();

        const links = screen.getAllByTestId('workspace-link');
        expect(links).toHaveLength(4);
        for (const link of links) expect(link.tagName).toBe('A');
    });

    it('encodes the authoritative tenant slug in every navigation target', () => {
        renderHub('alpha/../beta');

        for (const link of screen.getAllByRole('link')) {
            expect(link.getAttribute('href')).toContain('/tenants/alpha%2F..%2Fbeta/');
            expect(link.getAttribute('href')).not.toContain('/tenants/beta/');
        }
    });
});

describe('AIMW-AI-A746A1C3EB workspace card navigation', () => {
    it('binds the exact canonical operation to every rendered workspace card', () => {
        renderHub();

        expect(AI_WORKSPACE_CARD_OPERATION_ID).toBe('AIMW-AI-A746A1C3EB');
        for (const link of screen.getAllByTestId('workspace-link')) {
            expect(link).toHaveAttribute('data-canonical-operation', AI_WORKSPACE_CARD_OPERATION_ID);
        }
    });

    it('suppresses cards whose destination permission is absent instead of exposing dead links', () => {
        renderHub('alpha', ['tenant.view', 'approvals.view']);

        const links = screen.getAllByTestId('workspace-link');
        expect(links).toHaveLength(1);
        expect(links[0]).toHaveAttribute('data-workspace-key', 'approvals');
        expect(links[0]).toHaveAttribute('href', '/tenants/alpha/approvals');
        expect(screen.queryByRole('heading', { level: 3, name: 'AI Center' })).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { level: 3, name: 'AI Providers' })).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { level: 3, name: 'Prompt Templates' })).not.toBeInTheDocument();
    });

    it('requires ai.use for AI Center and settings.manage for both settings destinations', () => {
        const first = renderHub('alpha', ['tenant.view', 'ai.use']);
        expect(screen.getAllByTestId('workspace-link')).toHaveLength(1);
        expect(screen.getByTestId('workspace-link')).toHaveAttribute('data-workspace-key', 'ai-center');
        first.unmount();

        renderHub('alpha', ['tenant.view', 'settings.manage']);
        const settingsLinks = screen.getAllByTestId('workspace-link');
        expect(settingsLinks).toHaveLength(2);
        expect(settingsLinks.map((link) => link.getAttribute('data-workspace-key'))).toEqual(['ai-providers', 'ai-prompts']);
    });

    it('renders no workspace cards for a hub-only member with no destination permission', () => {
        renderHub('alpha', ['tenant.view']);

        expect(screen.queryAllByTestId('workspace-link')).toHaveLength(0);
        expect(screen.getByRole('link', { name: /Select Site/i })).toHaveAttribute('href', '/tenants/alpha/sites');
    });
});
