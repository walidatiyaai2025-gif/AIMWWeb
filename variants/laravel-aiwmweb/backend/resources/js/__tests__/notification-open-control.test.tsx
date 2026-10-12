import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';
import {
    NOTIFICATION_OPEN_OPERATION_ID,
    NotificationOpenControl,
    safeNotificationTarget,
} from '../notification-open-control';

const context = (slug = 'alpha'): FrontendContext => ({
    user: { id: 10, name: 'Alpha User', email: 'alpha@example.test' },
    tenant: { slug, name: 'Alpha' },
    tenants: [{ slug, name: 'Alpha' }],
    permissions: ['tenant.view', 'notifications.view'],
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('AIMW-EMAI-BDF888551C notification Open control', () => {
    it('opens an authoritative application-relative deep link inside the active tenant namespace', () => {
        render(
            <LocaleProvider>
                <NotificationOpenControl context={context()} row={{ deep_link: '/module/execution' }} />
            </LocaleProvider>,
        );

        const link = screen.getByRole('link', { name: /Open/i });
        expect(link).toHaveAttribute('href', '/tenants/alpha/module/execution');
        expect(link).toHaveAttribute('data-canonical-operation', NOTIFICATION_OPEN_OPERATION_ID);
        expect(NOTIFICATION_OPEN_OPERATION_ID).toBe('AIMW-EMAI-BDF888551C');
    });

    it('encodes the active tenant slug instead of accepting tenant data from the notification', () => {
        expect(safeNotificationTarget(context('alpha/../beta'), '/content-planner'))
            .toBe('/tenants/alpha%2F..%2Fbeta/content-planner');
    });

    it('fails closed for absent, external, protocol-relative, and control-character links', () => {
        expect(safeNotificationTarget(context(), null)).toBeNull();
        expect(safeNotificationTarget(context(), 'https://evil.test')).toBeNull();
        expect(safeNotificationTarget(context(), '//evil.test')).toBeNull();
        expect(safeNotificationTarget(context(), '/ok\nInjected')).toBeNull();
    });
});
