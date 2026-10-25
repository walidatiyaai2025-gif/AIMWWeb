import React from 'react';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import {
    SYSTEM_HEALTH_REFRESH_OPERATION_ID,
    SystemHealthRefreshControl,
} from '../system-health-refresh-control';

function deferred() {
    let resolve!: () => void;
    const promise = new Promise<void>((done) => {
        resolve = done;
    });

    return { promise, resolve };
}

function renderControl(busy: boolean, onRefresh: () => void | Promise<unknown>) {
    return render(
        <LocaleProvider>
            <SystemHealthRefreshControl busy={busy} onRefresh={onRefresh} />
        </LocaleProvider>,
    );
}

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.restoreAllMocks();
});

describe('AIMW-SYNC-0DFEE862FA SystemHealth RefreshAsync', () => {
    it('marks the real control and suppresses duplicate refresh activation until the read settles', async () => {
        const gate = deferred();
        const onRefresh = vi.fn(() => gate.promise);
        renderControl(false, onRefresh);

        const button = screen.getByRole('button', { name: 'Run checks' });
        expect(button).toHaveAttribute('data-canonical-operation', SYSTEM_HEALTH_REFRESH_OPERATION_ID);
        expect(SYSTEM_HEALTH_REFRESH_OPERATION_ID).toBe('AIMW-SYNC-0DFEE862FA');
        expect(button).toHaveAttribute('aria-busy', 'false');

        fireEvent.click(button);
        fireEvent.click(button);

        expect(onRefresh).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();
        expect(button).toHaveAccessibleName('Checking...');
        expect(button).toHaveAttribute('aria-busy', 'true');

        gate.resolve();

        await waitFor(() => expect(button).not.toBeDisabled());
        expect(button).toHaveAccessibleName('Run checks');
    });

    it('fails closed while the authoritative query is already fetching', () => {
        const onRefresh = vi.fn();
        renderControl(true, onRefresh);

        const button = screen.getByRole('button', { name: 'Checking...' });
        expect(button).toBeDisabled();

        fireEvent.click(button);

        expect(onRefresh).not.toHaveBeenCalled();
    });

    it('keeps Arabic refresh feedback aligned with the canonical source', () => {
        localStorage.setItem('aiwm.locale', 'ar');
        const onRefresh = vi.fn();
        renderControl(false, onRefresh);

        expect(screen.getByRole('button', { name: 'إعادة الفحص' }))
            .toHaveAttribute('data-canonical-operation', 'AIMW-SYNC-0DFEE862FA');
    });
});
