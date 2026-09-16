import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    APP_BUTTON_CLICK_OPERATION_ID,
    APP_BUTTON_LINK_OPERATION_ID,
    AppButton,
} from '../app-button';

describe('AIMW-PLAT-827A8F1C0D AppButton effective href contract', () => {
    it('renders a real anchor for a nonblank href and preserves navigation metadata', () => {
        render(
            <AppButton
                href="/release-notes"
                target="_blank"
                title="Open release notes"
                aria-label="Open release notes"
            >
                Release notes
            </AppButton>,
        );

        const link = screen.getByRole('link', { name: 'Open release notes' });
        expect(APP_BUTTON_LINK_OPERATION_ID).toBe('AIMW-PLAT-827A8F1C0D');
        expect(link.tagName).toBe('A');
        expect(link).toHaveAttribute('href', '/release-notes');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('title', 'Open release notes');
        expect(link).toHaveAttribute('data-canonical-operation', APP_BUTTON_LINK_OPERATION_ID);
        expect(link).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_LINK_OPERATION_ID);
    });

    it('fails closed when disabled by removing the effective href and tab reachability', () => {
        const onClick = vi.fn();
        render(
            <AppButton
                href="/release-notes"
                disabled
                onClick={onClick}
                aria-label="Disabled release notes"
            >
                Disabled release notes
            </AppButton>,
        );

        const anchor = screen.getByText('Disabled release notes').closest('a');
        expect(anchor).not.toBeNull();
        expect(anchor).not.toHaveAttribute('href');
        expect(anchor).toHaveAttribute('tabindex', '-1');
        expect(anchor).toHaveAttribute('aria-disabled', 'true');
        fireEvent.click(anchor as HTMLAnchorElement);
        expect(onClick).not.toHaveBeenCalled();
    });

    it('preserves busy, pressed and accessible labeling while retaining visible content', () => {
        render(
            <AppButton
                href="/release-notes"
                busy
                ariaPressed
                title="Current release"
                aria-label="Current release"
            >
                Current release
            </AppButton>,
        );

        const link = screen.getByRole('link', { name: 'Current release' });
        expect(link).toHaveAttribute('aria-busy', 'true');
        expect(link).toHaveAttribute('aria-pressed', 'true');
        expect(link).toHaveAttribute('title', 'Current release');
        expect(link).toHaveTextContent('Current release');
        expect(link.querySelector('.app-button__spinner')).not.toBeNull();
    });

    it('treats whitespace href as the existing non-link button branch', () => {
        const onClick = vi.fn();
        render(
            <AppButton href="   " onClick={onClick} aria-label="Fallback action">
                Fallback action
            </AppButton>,
        );

        const button = screen.getByRole('button', { name: 'Fallback action' });
        expect(button).toHaveAttribute('data-canonical-operation', APP_BUTTON_CLICK_OPERATION_ID);
        expect(button).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_CLICK_OPERATION_ID);
        fireEvent.click(button);
        expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('keeps a consumer identity separate from the shared link component identity', () => {
        render(
            <AppButton
                href="/release-notes"
                canonicalOperationId="AIMW-CONT-0154E7772B"
                aria-label="Consumer navigation"
            >
                Consumer navigation
            </AppButton>,
        );

        const link = screen.getByRole('link', { name: 'Consumer navigation' });
        expect(link).toHaveAttribute('data-canonical-operation', 'AIMW-CONT-0154E7772B');
        expect(link).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_LINK_OPERATION_ID);
        expect(document.querySelectorAll(`[data-canonical-operation="${APP_BUTTON_LINK_OPERATION_ID}"]`)).toHaveLength(0);
    });
});
