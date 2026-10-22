import React from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { CURRENT_USER_SIGN_OUT_OPERATION, CurrentUserSignOutControl } from '../current-user-sign-out-control';
import { LocaleProvider } from '../i18n';

afterEach(() => {
    cleanup();
    document.querySelector('meta[name="csrf-token"]')?.remove();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

function installTopbar() {
    const topbar = document.createElement('div');
    topbar.className = 'topbar-actions';
    document.body.appendChild(topbar);
    return topbar;
}

function installCsrfToken(value = 'csrf-test-token') {
    const meta = document.createElement('meta');
    meta.name = 'csrf-token';
    meta.content = value;
    document.head.appendChild(meta);
}

describe('AIMW-IDEN-BF78057C28 current-user sign out', () => {
    it('renders the canonical Sign out control into the authenticated topbar target', async () => {
        installTopbar();

        render(
            <LocaleProvider>
                <CurrentUserSignOutControl onSignedOut={() => undefined} />
            </LocaleProvider>,
        );

        const button = await screen.findByRole('button', { name: /Sign out/i });
        expect(button).toHaveAttribute('data-canonical-operation', CURRENT_USER_SIGN_OUT_OPERATION);
        expect(CURRENT_USER_SIGN_OUT_OPERATION).toBe('AIMW-IDEN-BF78057C28');
        expect(button.closest('.topbar-actions')).not.toBeNull();
    });

    it('posts through the real CSRF-aware session endpoint and only completes after server confirmation', async () => {
        installTopbar();
        installCsrfToken();
        const onSignedOut = vi.fn();
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ ok: true }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        render(
            <LocaleProvider>
                <CurrentUserSignOutControl onSignedOut={onSignedOut} />
            </LocaleProvider>,
        );

        const button = await screen.findByRole('button', { name: /Sign out/i });
        await userEvent.click(button);

        await waitFor(() => expect(onSignedOut).toHaveBeenCalledTimes(1));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/api/logout');
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('same-origin');
        const headers = init.headers as Headers;
        expect(headers.get('Accept')).toBe('application/json');
        expect(headers.get('X-Requested-With')).toBe('XMLHttpRequest');
        expect(headers.get('X-CSRF-TOKEN')).toBe('csrf-test-token');
    });

    it('fails closed when logout fails and leaves the session UI available for retry', async () => {
        installTopbar();
        installCsrfToken();
        const onSignedOut = vi.fn();
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: 'Session service unavailable.' }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' },
        }));
        vi.stubGlobal('fetch', fetchMock);

        render(
            <LocaleProvider>
                <CurrentUserSignOutControl onSignedOut={onSignedOut} />
            </LocaleProvider>,
        );

        const button = await screen.findByRole('button', { name: /Sign out/i });
        await userEvent.click(button);

        expect(await screen.findByRole('alert')).toHaveTextContent('Session service unavailable.');
        expect(onSignedOut).not.toHaveBeenCalled();
        await waitFor(() => expect(button).not.toBeDisabled());
    });
});
