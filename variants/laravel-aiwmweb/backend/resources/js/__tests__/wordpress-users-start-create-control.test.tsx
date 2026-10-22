import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ActionButton, WORDPRESS_USERS_START_CREATE_OPERATION_ID } from '../components';
import { workspaceRoutes, type ActionContract, type FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const route = workspaceRoutes.find((candidate) => candidate.key === 'wp-users')!;

const contract: ActionContract = {
    endpoint: '/api/v1/tenants/alpha/sites/17/users/save',
    method: 'POST',
    capability: 'wordpress-users.save',
    fields: [
        { key: 'username', type: 'text', label: { en: 'Username', ar: 'اسم المستخدم' } },
        { key: 'email', type: 'email', label: { en: 'Email', ar: 'البريد الإلكتروني' }, required: true },
    ],
};

function context(overrides: Partial<FrontendContext> = {}): FrontendContext {
    return {
        user: { id: 1, name: 'Owner', email: 'owner@example.test' },
        tenant: { slug: 'alpha', name: 'Alpha' },
        tenants: [{ slug: 'alpha', name: 'Alpha' }],
        permissions: ['tenant.view', 'sites.view', 'content.edit'],
        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['users.read'] }],
        capabilities: {},
        api: { 'wp-users': '/api/v1/tenants/alpha/sites/17/users' },
        actions: { 'wordpress-users.save': contract },
        ...overrides,
    };
}

function renderStartCreate(value: FrontendContext, onAvailable = vi.fn()) {
    render(
        <LocaleProvider>
            <ActionButton
                route={route}
                actionKey="wordpress-users.save"
                context={value}
                onAvailable={onAvailable}
            />
        </LocaleProvider>,
    );
    return onAvailable;
}

describe('AIMW-CONT-BCE1211CE3 WordPress Users StartCreate control', () => {
    it('marks the existing authoritative dialog-launch control and opens the real save contract', () => {
        const onAvailable = renderStartCreate(context());
        const button = screen.getByRole('button', { name: 'Save' });

        expect(button).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_START_CREATE_OPERATION_ID);
        expect(WORDPRESS_USERS_START_CREATE_OPERATION_ID).toBe('AIMW-CONT-BCE1211CE3');

        fireEvent.click(button);
        expect(onAvailable).toHaveBeenCalledTimes(1);
        expect(onAvailable).toHaveBeenCalledWith(contract);
    });

    it('fails closed when the server does not advertise the mutation contract', () => {
        const onAvailable = renderStartCreate(context({ actions: {} }));
        const button = screen.getByRole('button', { name: 'Save' });

        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onAvailable).not.toHaveBeenCalled();
    });

    it('fails closed when the WordPress users connector scope is unavailable', () => {
        const onAvailable = renderStartCreate(context({ connectors: [] }));
        const button = screen.getByRole('button', { name: 'Save' });

        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onAvailable).not.toHaveBeenCalled();
    });

    it('does not mark unrelated action buttons with the StartCreate canonical operation', () => {
        const unrelated = workspaceRoutes.find((candidate) => candidate.key === 'sites')!;
        render(
            <LocaleProvider>
                <ActionButton
                    route={unrelated}
                    actionKey="sites.connect"
                    context={context({
                        connectors: [{ key: 'wordpress', state: 'connected', scopes: ['users.read', 'pairing'] }],
                        actions: { 'sites.connect': contract },
                    })}
                    onAvailable={vi.fn()}
                />
            </LocaleProvider>,
        );

        expect(screen.getByRole('button', { name: 'Connect' })).not.toHaveAttribute('data-canonical-operation');
    });
});
