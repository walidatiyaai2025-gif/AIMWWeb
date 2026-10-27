import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { LocaleProvider } from '../i18n';
import { STATE_PANEL_RETRY_OPERATION_ID, StatePanel } from '../components';

function renderPanel(props: Partial<React.ComponentProps<typeof StatePanel>> = {}) {
    const onRetry = props.onRetry ?? vi.fn();
    const result = render(
        <LocaleProvider>
            <StatePanel
                tone="danger"
                title="Data could not be loaded"
                onRetry={onRetry}
                {...props}
            >
                Authoritative request failed.
            </StatePanel>
        </LocaleProvider>,
    );
    return { ...result, onRetry };
}

afterEach(() => cleanup());

describe('AIMW-SYNC-AE544B4684 shared StatePanel retry control', () => {
    it('invokes exactly the supplied authoritative retry callback and carries the canonical marker', () => {
        const onRetry = vi.fn();
        renderPanel({ onRetry, retryText: 'Retry now' });

        const button = screen.getByRole('button', { name: 'Retry now' });
        expect(button).toHaveAttribute('data-canonical-operation', STATE_PANEL_RETRY_OPERATION_ID);
        expect(STATE_PANEL_RETRY_OPERATION_ID).toBe('AIMW-SYNC-AE544B4684');

        fireEvent.click(button);
        expect(onRetry).toHaveBeenCalledTimes(1);
    });

    it('fails closed while busy and exposes deterministic retrying state', () => {
        const onRetry = vi.fn();
        renderPanel({
            onRetry,
            retryBusy: true,
            retryText: 'Try again',
            retryingText: 'Trying again...',
        });

        const button = screen.getByRole('button', { name: 'Try again' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByText('Trying again...')).toBeInTheDocument();

        fireEvent.click(button);
        expect(onRetry).not.toHaveBeenCalled();
    });

    it('renders no retry control unless the caller supplies a real retry callback', () => {
        render(
            <LocaleProvider>
                <StatePanel title="No retry">No callback exists.</StatePanel>
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /try again/i })).not.toBeInTheDocument();
    });

    it('preserves caller-provided actions alongside the canonical retry control', () => {
        renderPanel({ action: <a href="/help">Help</a> });

        expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Help' })).toHaveAttribute('href', '/help');
    });
});
