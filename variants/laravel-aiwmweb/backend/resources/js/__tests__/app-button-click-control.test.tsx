import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { APP_BUTTON_CLICK_OPERATION_ID, AppButton } from '../app-button';
import { RUNTIME_ERROR_RECOVER_OPERATION_ID, RuntimeErrorBoundary } from '../runtime-error-boundary';

function AlwaysThrows(): React.JSX.Element {
    throw new Error('app-button production wiring probe');
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('AIMW-PLAT-57E0113F24 AppButton non-link click contract', () => {
    it('renders a real typed button and dispatches the supplied click callback', () => {
        const onClick = vi.fn();
        render(
            <AppButton type="button" title="Run action" aria-label="Run action" onClick={onClick}>
                Run
            </AppButton>,
        );

        const button = screen.getByRole('button', { name: 'Run action' });
        expect(APP_BUTTON_CLICK_OPERATION_ID).toBe('AIMW-PLAT-57E0113F24');
        expect(button.tagName).toBe('BUTTON');
        expect(button).toHaveAttribute('type', 'button');
        expect(button).toHaveAttribute('title', 'Run action');
        expect(button).toHaveAttribute('data-canonical-operation', APP_BUTTON_CLICK_OPERATION_ID);
        expect(button).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_CLICK_OPERATION_ID);

        fireEvent.click(button);
        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('uses the native disabled contract to suppress interaction', () => {
        const onClick = vi.fn();
        render(<AppButton disabled onClick={onClick}>Disabled action</AppButton>);

        const button = screen.getByRole('button', { name: 'Disabled action' });
        expect(button).toBeDisabled();
        fireEvent.click(button);
        expect(onClick).not.toHaveBeenCalled();
    });

    it('preserves busy and pressed accessibility state while retaining visible content', () => {
        render(
            <AppButton busy ariaPressed title="Toggle state" aria-label="Toggle state">
                Toggle
            </AppButton>,
        );

        const button = screen.getByRole('button', { name: 'Toggle state' });
        expect(button).toHaveAttribute('aria-busy', 'true');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(button).toHaveAttribute('title', 'Toggle state');
        expect(button).toHaveTextContent('Toggle');
        expect(button.querySelector('.app-button__spinner')).not.toBeNull();
    });

    it('is exercised by a real production control without stealing that controls canonical identity', () => {
        vi.spyOn(console, 'error').mockImplementation(() => undefined);
        render(
            <RuntimeErrorBoundary>
                <AlwaysThrows />
            </RuntimeErrorBoundary>,
        );

        const recover = screen.getByRole('button', { name: 'Try to recover' });
        expect(recover).toHaveAttribute('data-canonical-operation', RUNTIME_ERROR_RECOVER_OPERATION_ID);
        expect(recover).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_CLICK_OPERATION_ID);
        expect(RUNTIME_ERROR_RECOVER_OPERATION_ID).toBe('AIMW-PLAT-C6260410D1');
        expect(document.querySelectorAll(`[data-canonical-operation="${APP_BUTTON_CLICK_OPERATION_ID}"]`)).toHaveLength(0);
    });
});
