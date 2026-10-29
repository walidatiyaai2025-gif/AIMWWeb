import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ActionButton, TAXONOMY_START_CREATE_OPERATION_ID } from '../components';
import { workspaceRoutes, type ActionContract, type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const route = workspaceRoutes.find((candidate) => candidate.key === 'taxonomy')!;

const contract: ActionContract = {
    endpoint: '/api/v1/tenants/alpha/sites/17/taxonomy',
    method: 'POST',
    capability: 'taxonomy.manage',
    fields: [
        {
            key: 'taxonomy',
            type: 'select',
            label: { en: 'Taxonomy', ar: 'التصنيف' },
            required: true,
            options: [
                { value: 'category', label: { en: 'Category', ar: 'تصنيف' } },
                { value: 'post_tag', label: { en: 'Tag', ar: 'وسم' } },
            ],
        },
        { key: 'name', type: 'text', label: { en: 'Name', ar: 'الاسم' }, required: true },
    ],
};

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.view', 'content.edit'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['taxonomy.read'] }],
        capabilities: {},
        api: { taxonomy: '/api/v1/tenants/alpha/sites/17/taxonomy' },
        actions: { 'taxonomy.manage': contract },
        ...overrides,
    };
}

function renderStartCreate(value: FrontendContext, onAvailable = vi.fn()) {
    render(
        <LocaleProvider>
            <ActionButton
                route={route}
                actionKey="taxonomy.manage"
                context={value}
                onAvailable={onAvailable}
            />
        </LocaleProvider>,
    );
    return onAvailable;
}

describe('AIMW-TAXO-005925568C Taxonomy StartCreate control', () => {
    it('marks the existing Add New control and opens the real taxonomy manage contract without mutating', () => {
        const onAvailable = renderStartCreate(context());
        const button = screen.getByRole('button', { name: 'Add new' });

        expect(button).toHaveAttribute('data-canonical-operation', TAXONOMY_START_CREATE_OPERATION_ID);
        expect(TAXONOMY_START_CREATE_OPERATION_ID).toBe('AIMW-TAXO-005925568C');

        fireEvent.click(button);
        expect(onAvailable).toHaveBeenCalledTimes(1);
        expect(onAvailable).toHaveBeenCalledWith(contract);
    });

    it('fails closed when the server does not advertise the taxonomy mutation contract', () => {
        const onAvailable = renderStartCreate(context({ actions: {} }));
        const button = screen.getByRole('button', { name: 'Add new' });

        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onAvailable).not.toHaveBeenCalled();
    });

    it('fails closed when the WordPress taxonomy connector scope is unavailable', () => {
        const onAvailable = renderStartCreate(context({ connectors: [] }));
        const button = screen.getByRole('button', { name: 'Add new' });

        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onAvailable).not.toHaveBeenCalled();
    });

    it('does not apply the taxonomy operation marker to unrelated action buttons', () => {
        const unrelated = workspaceRoutes.find((candidate) => candidate.key === 'sites')!;
        render(
            <LocaleProvider>
                <ActionButton
                    route={unrelated}
                    actionKey="sites.connect"
                    context={context({
                        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['taxonomy.read', 'pairing'] }],
                        actions: { 'sites.connect': contract },
                    })}
                    onAvailable={vi.fn()}
                />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Connect' }))
            .not.toHaveAttribute('data-canonical-operation', TAXONOMY_START_CREATE_OPERATION_ID);
    });
});
