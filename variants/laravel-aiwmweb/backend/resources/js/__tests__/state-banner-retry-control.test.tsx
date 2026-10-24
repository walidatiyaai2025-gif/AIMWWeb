import React from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { STATE_BANNER_RETRY_OPERATION_ID, StateBanner } from '../components';
import { LocaleProvider } from '../i18n';

function renderBanner(props: Partial<React.ComponentProps<typeof StateBanner>> = {}) {
    const onRetry = props.onRetry ?? vi.fn();
    const result = render(
        <LocaleProvider>
            <StateBanner
                title="Refresh failed — showing last successful data"
                kind="cached"
                description="usage backend unavailable"
                freshnessText="Last successful refresh: now"
                onRetry={onRetry}
                {...props}
            />
        </LocaleProvider>,
    );
    return { ...result, onRetry };
}

afterEach(() => cleanup());

describe('AIMW-SYNC-8719C812DC shared StateBanner retry control', () => {
    it('retains successful content semantics and exposes the canonical retry operation', () => {
        const onRetry = vi.fn();
        renderBanner({ onRetry, retryText: 'Retry refresh' });

        const banner = screen.getByText('Refresh failed — showing last successful data').closest('aside');
        expect(banner).toHaveAttribute('data-retains-content', 'true');
        expect(banner).toHaveAttribute('data-state', 'cached');
        expect(banner).toHaveAttribute('role', 'status');
        expect(banner).toHaveAttribute('aria-live', 'polite');
        expect(screen.getByText('usage backend unavailable')).toBeInTheDocument();
        expect(screen.getByText('Last successful refresh: now')).toBeInTheDocument();

        const retry = screen.getByRole('button', { name: 'Retry refresh' });
        expect(retry).toHaveAttribute('data-canonical-operation', STATE_BANNER_RETRY_OPERATION_ID);
        expect(STATE_BANNER_RETRY_OPERATION_ID).toBe('AIMW-SYNC-8719C812DC');

        fireEvent.click(retry);
        expect(onRetry).toHaveBeenCalledTimes(1);
    });

    it('blocks duplicate retry while busy and exposes the retrying label', () => {
        const onRetry = vi.fn();
        renderBanner({
            onRetry,
            busy: true,
            retryText: 'Retry',
            retryingText: 'Retrying...',
        });

        const retry = screen.getByRole('button', { name: 'Retry' });
        expect(retry).toBeDisabled();
        expect(retry).toHaveAttribute('aria-busy', 'true');
        expect(screen.getByText('Retrying...')).toBeInTheDocument();

        fireEvent.click(retry);
        expect(onRetry).not.toHaveBeenCalled();
    });

    it('normalizes danger to error and uses assertive live-region semantics', () => {
        renderBanner({ kind: 'danger', onRetry: undefined });

        const banner = screen.getByText('Refresh failed — showing last successful data').closest('aside');
        expect(banner).toHaveAttribute('data-state', 'error');
        expect(banner).toHaveAttribute('role', 'alert');
        expect(banner).toHaveAttribute('aria-live', 'assertive');
    });

    it('renders no retry control without an authoritative retry callback', () => {
        render(
            <LocaleProvider>
                <StateBanner title="Informational state" description="No retry exists." />
            </LocaleProvider>,
        );

        expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
    });
});
