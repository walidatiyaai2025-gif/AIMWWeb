import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    LIVE_SYSTEM_STATUS_REFRESH_OPERATION_ID,
    LiveSystemStatusRefreshControl,
} from '../live-system-status-refresh-control';
import { LocaleProvider } from '../i18n';

function renderControl(busy: boolean, onRefresh: () => void | Promise<unknown>) {
    return render(
        <LocaleProvider>
            <LiveSystemStatusRefreshControl busy={busy} onRefresh={onRefresh} />
        </LocaleProvider>,
    );
}

afterEach(() => cleanup());

describe('AIMW-SYNC-F0C46A9BD1 LiveSystemStatus RefreshAsync', () => {
    it('marks the real refresh control and invokes exactly one authoritative refresh', () => {
        const onRefresh = vi.fn();
        renderControl(false, onRefresh);

        const button = screen.getByRole('button', { name: 'Refresh' });
        expect(button).toHaveAttribute('data-canonical-operation', LIVE_SYSTEM_STATUS_REFRESH_OPERATION_ID);
        expect(LIVE_SYSTEM_STATUS_REFRESH_OPERATION_ID).toBe('AIMW-SYNC-F0C46A9BD1');
        expect(button).toHaveAttribute('aria-busy', 'false');

        fireEvent.click(button);

        expect(onRefresh).toHaveBeenCalledTimes(1);
    });

    it('fails closed against duplicate refresh while the authoritative read is already running', () => {
        const onRefresh = vi.fn();
        renderControl(true, onRefresh);

        const button = screen.getByRole('button', { name: 'Refreshing' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');

        fireEvent.click(button);

        expect(onRefresh).not.toHaveBeenCalled();
    });
});
