import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { apiRequest } from './core';
import { useLocale } from './i18n';

export const CURRENT_USER_SIGN_OUT_OPERATION = 'AIMW-IDEN-BF78057C28';

type LogoutResponse = { ok: boolean };

export async function endCurrentSession(): Promise<LogoutResponse> {
    const response = await apiRequest<LogoutResponse>('/api/logout', { method: 'POST' });
    if (response.ok !== true) throw new Error('The server did not confirm sign out.');
    return response;
}

export function CurrentUserSignOutControl({ onSignedOut }: { onSignedOut?: () => void }) {
    const { locale } = useLocale();
    const [target, setTarget] = useState<HTMLElement | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setTarget(document.querySelector<HTMLElement>('.topbar-actions'));
    }, []);

    const signOut = async () => {
        if (busy) return;
        setBusy(true);
        setError(null);

        try {
            await endCurrentSession();
            if (onSignedOut) onSignedOut();
            else window.location.assign('/');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : (locale === 'ar' ? 'تعذر تسجيل الخروج.' : 'Sign out failed.'));
            setBusy(false);
        }
    };

    if (!target) return null;

    return createPortal(
        <span className="control-with-reason current-user-sign-out-control">
            <button
                type="button"
                className="btn"
                data-canonical-operation={CURRENT_USER_SIGN_OUT_OPERATION}
                disabled={busy}
                aria-busy={busy}
                onClick={signOut}
                title={locale === 'ar' ? 'إنهاء الجلسة الحالية بأمان' : 'End the current session securely'}
            >
                <span aria-hidden="true">↪</span>{' '}
                {busy ? (locale === 'ar' ? 'جارٍ تسجيل الخروج…' : 'Signing out…') : (locale === 'ar' ? 'تسجيل الخروج' : 'Sign out')}
            </button>
            {error ? <small role="alert" className="field-error">{error}</small> : null}
        </span>,
        target,
    );
}
