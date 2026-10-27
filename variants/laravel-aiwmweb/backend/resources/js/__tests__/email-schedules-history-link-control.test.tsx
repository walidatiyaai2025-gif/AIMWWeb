import React from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import {
    EMAIL_SCHEDULES_HISTORY_LINK_OPERATION_ID,
    EmailSchedulesHistoryLinkControl,
} from '../email-schedules-history-link-control';
import type { FrontendContext } from '../core';
import { LocaleProvider } from '../i18n';

const context = (permissions = ['operations.manage', 'tenant.manage', 'diagnostics.view']): FrontendContext => ({
    user: { id: 1, name: 'Alpha', email: 'alpha@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

function renderControl(value = context()) {
    return render(
        <MemoryRouter>
            <LocaleProvider>
                <EmailSchedulesHistoryLinkControl context={value} />
            </LocaleProvider>
        </MemoryRouter>,
    );
}

describe('AIMW-EMAI-EC34E40629 Email Schedules delivery-history link', () => {
    it('opens the real tenant-qualified delivery history route', () => {
        renderControl();
        const link = screen.getByRole('link', { name: 'Delivery history' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/email/history');
        expect(link).toHaveAttribute('data-canonical-operation', EMAIL_SCHEDULES_HISTORY_LINK_OPERATION_ID);
        expect(link).toHaveAttribute('data-source-path', '/email/schedules');
        expect(link).toHaveAttribute('data-source-control-target', '/email/history');
    });

    it('fails closed when either source or destination authorization is missing', () => {
        const { rerender } = renderControl(context(['operations.manage', 'tenant.manage']));
        expect(screen.queryByRole('link', { name: 'Delivery history' })).not.toBeInTheDocument();

        rerender(
            <MemoryRouter>
                <LocaleProvider>
                    <EmailSchedulesHistoryLinkControl context={context(['tenant.manage', 'diagnostics.view'])} />
                </LocaleProvider>
            </MemoryRouter>,
        );
        expect(screen.queryByRole('link', { name: 'Delivery history' })).not.toBeInTheDocument();
    });
});
