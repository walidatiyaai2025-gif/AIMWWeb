import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { APP_BUTTON_CLICK_OPERATION_ID, AppButton } from '../app-button';

describe('AIMW-PLAT-57E0113F24 AppButton non-link click contract', () => {
    it('renders a real typed button and dispatches the supplied click callback', () => {
        const onClick = vi.fn();
        render(<AppButton type="button" title="Run action" aria-label="Run action" onClick={onClick}>Run</AppButton>);
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
        render(<AppButton busy ariaPressed title="Toggle state" aria-label="Toggle state">Toggle</AppButton>);
        const button = screen.getByRole('button', { name: 'Toggle state' });
        expect(button).toHaveAttribute('aria-busy', 'true');
        expect(button).toHaveAttribute('aria-pressed', 'true');
        expect(button).toHaveAttribute('title', 'Toggle state');
        expect(button).toHaveTextContent('Toggle');
        expect(button.querySelector('.app-button__spinner')).not.toBeNull();
    });

    it('keeps a consumer canonical identity separate from the shared component identity', () => {
        const onClick = vi.fn();
        render(
            <AppButton
                canonicalOperationId="AIMW-PLAT-C6260410D1"
                aria-label="Consumer action"
                onClick={onClick}
            >Consumer action</AppButton>,
        );

        const button = screen.getByRole('button', { name: 'Consumer action' });
        expect(button).toHaveAttribute('data-canonical-operation', 'AIMW-PLAT-C6260410D1');
        expect(button).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_CLICK_OPERATION_ID);
        expect(document.querySelectorAll(`[data-canonical-operation="${APP_BUTTON_CLICK_OPERATION_ID}"]`)).toHaveLength(0);
        fireEvent.click(button);
        expect(onClick).toHaveBeenCalledTimes(1);
    });
});
