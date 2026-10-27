import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import {
    GLOBAL_NOTIFICATION_LOGS_OPERATION_ID,
    ToastProvider,
    useToast,
} from '../components';
import { LocaleProvider } from '../i18n';

function Trigger({
    tone,
    message,
}: {
    tone: 'success' | 'error' | 'info';
    message: string;
}) {
    const { notify } = useToast();
    return <button type="button" onClick={() => notify(message, tone)}>Trigger</button>;
}

const renderToast = ({
    tone = 'error',
    message = 'SMTP delivery failed with correlation E-42.',
    logsHref = '/tenants/alpha/logs',
}: {
    tone?: 'success' | 'error' | 'info';
    message?: string;
    logsHref?: string;
} = {}) => render(
    <LocaleProvider>
        <ToastProvider logsHref={logsHref}>
            <Trigger tone={tone} message={message} />
        </ToastProvider>
    </LocaleProvider>,
);

afterEach(() => {
    cleanup();
    window.localStorage.clear();
});

describe('AIMW-EMAI-9EB09C490D global notification logs link adaptation', () => {
    it('renders the canonical tenant-scoped logs link for a non-empty error toast', () => {
        renderToast();

        fireEvent.click(screen.getByRole('button', { name: 'Trigger' }));

        const link = screen.getByRole('link', { name: 'View details in logs' });
        expect(link).toHaveAttribute('href', '/tenants/alpha/logs');
        expect(link).toHaveAttribute('data-canonical-operation', GLOBAL_NOTIFICATION_LOGS_OPERATION_ID);
        expect(GLOBAL_NOTIFICATION_LOGS_OPERATION_ID).toBe('AIMW-EMAI-9EB09C490D');
    });

    it('does not expose the logs link for informational or empty notifications', () => {
        const first = renderToast({ tone: 'info' });
        fireEvent.click(screen.getByRole('button', { name: 'Trigger' }));
        expect(screen.queryByRole('link', { name: 'View details in logs' })).not.toBeInTheDocument();
        first.unmount();

        renderToast({ tone: 'error', message: '' });
        fireEvent.click(screen.getByRole('button', { name: 'Trigger' }));
        expect(screen.queryByRole('link', { name: 'View details in logs' })).not.toBeInTheDocument();
    });
});
