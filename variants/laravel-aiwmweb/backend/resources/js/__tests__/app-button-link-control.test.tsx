import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import {
    APP_BUTTON_CLICK_OPERATION_ID,
    APP_BUTTON_LINK_OPERATION_ID,
    AppButton,
} from '../app-button';

describe('AIMW-PLAT-827A8F1C0D AppButton link contract', () => {
    it('renders the source-equivalent anchor with effective href, target, title, and accessibility state', () => {
        render(
            <AppButton
                href="/settings/profile"
                target="_blank"
                title="Open profile"
                aria-label="Open profile"
                busy
                ariaPressed
            >Profile</AppButton>,
        );

        const link = screen.getByRole('link', { name: 'Open profile' });
        expect(APP_BUTTON_LINK_OPERATION_ID).toBe('AIMW-PLAT-827A8F1C0D');
        expect(link.tagName).toBe('A');
        expect(link).toHaveAttribute('href', '/settings/profile');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('title', 'Open profile');
        expect(link).toHaveAttribute('aria-disabled', 'false');
        expect(link).toHaveAttribute('aria-busy', 'true');
        expect(link).toHaveAttribute('aria-pressed', 'true');
        expect(link).toHaveAttribute('data-canonical-operation', APP_BUTTON_LINK_OPERATION_ID);
        expect(link).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_LINK_OPERATION_ID);
        expect(link.querySelector('.app-button__spinner')).not.toBeNull();
        expect(link).toHaveTextContent('Profile');
    });

    it('fails closed when disabled by retaining anchor semantics while removing navigation', () => {
        render(
            <AppButton href="/dangerous-destination" disabled title="Unavailable destination">
                Unavailable
            </AppButton>,
        );

        const anchor = screen.getByText('Unavailable').closest('a');
        expect(anchor).not.toBeNull();
        expect(anchor).not.toHaveAttribute('href');
        expect(anchor).toHaveAttribute('aria-disabled', 'true');
        expect(anchor).toHaveAttribute('tabindex', '-1');
        expect(anchor).toHaveAttribute('title', 'Unavailable destination');
        expect(anchor).toHaveAttribute('data-canonical-operation', APP_BUTTON_LINK_OPERATION_ID);
    });

    it('treats whitespace href as the existing non-link button branch without reopening click parity', () => {
        render(<AppButton href="   " target="_blank">Fallback action</AppButton>);

        const button = screen.getByRole('button', { name: 'Fallback action' });
        expect(button.tagName).toBe('BUTTON');
        expect(button).not.toHaveAttribute('href');
        expect(button).not.toHaveAttribute('target');
        expect(button).toHaveAttribute('data-canonical-operation', APP_BUTTON_CLICK_OPERATION_ID);
        expect(button).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_CLICK_OPERATION_ID);
    });

    it('keeps a consumer identity separate from the shared link-operation identity', () => {
        render(
            <AppButton
                href="/consumer-route"
                canonicalOperationId="AIMW-PLAT-C6260410D1"
                aria-label="Consumer route"
            >Consumer route</AppButton>,
        );

        const link = screen.getByRole('link', { name: 'Consumer route' });
        expect(link).toHaveAttribute('data-canonical-operation', 'AIMW-PLAT-C6260410D1');
        expect(link).toHaveAttribute('data-canonical-component-operation', APP_BUTTON_LINK_OPERATION_ID);
        expect(document.querySelectorAll(`[data-canonical-operation="${APP_BUTTON_LINK_OPERATION_ID}"]`)).toHaveLength(0);
    });
});
