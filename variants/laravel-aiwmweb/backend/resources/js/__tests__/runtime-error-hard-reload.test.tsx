import React from 'react';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import {
    RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID,
    RuntimeErrorHardReloadControl,
} from '../runtime-error-hard-reload-control';

const originalUrl = window.location.href;

afterEach(() => {
    window.history.replaceState({}, '', originalUrl);
});

describe('AIMW-PLAT-4BAE8344AF runtime hard reload control', () => {
    it('uses a raw document anchor bound to the exact current URL', () => {
        window.history.replaceState({}, '', '/tenants/alpha/module/content?tab=failed#trace-17');
        render(<RuntimeErrorHardReloadControl />);
        const link = screen.getByRole('link', { name: 'Hard reload' });
        expect(link.tagName).toBe('A');
        expect(link).toHaveAttribute('href', window.location.href);
        expect(link).toHaveAttribute('data-canonical-operation', RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID);
        expect(RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID).toBe('AIMW-PLAT-4BAE8344AF');
    });

    it('preserves path, query, and fragment instead of synthesizing a tenant or API destination', () => {
        window.history.replaceState({}, '', '/operator/runtime?tenant=alpha&retry=0#failure');
        render(<RuntimeErrorHardReloadControl />);
        const href = screen.getByRole('link', { name: 'Hard reload' }).getAttribute('href');
        expect(href).toBe(window.location.href);
        expect(new URL(href ?? '').pathname).toBe('/operator/runtime');
        expect(new URL(href ?? '').search).toBe('?tenant=alpha&retry=0');
        expect(new URL(href ?? '').hash).toBe('#failure');
        expect(href).not.toContain('/api/');
    });
});
