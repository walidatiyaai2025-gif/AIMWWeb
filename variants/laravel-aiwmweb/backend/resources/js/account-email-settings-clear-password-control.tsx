import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ACCOUNT_EMAIL_CLEAR_PASSWORD_OPERATION_ID = 'AIMW-EMAI-54E7EEFB15';

function authorized(context: FrontendContext): boolean {
    return context.permissions.includes('*') || context.permissions.includes('tenant.manage');
}

export function AccountEmailSettingsClearPasswordControl({
    context,
    hasSecret,
    onCleared,
}: {
    context: FrontendContext;
    hasSecret: boolean;
    onCleared?: () => Promise<unknown> | unknown;
}) {
    const { locale } = useLocale();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!authorized(context) || !hasSecret) return null;

    const endpoint = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/email/configuration/secret`;

    const clear = async () => {
        if (busy) return;
        setBusy(true);
        setError(null);
        try {
            await apiRequest(endpoint, {
                method: 'DELETE',
                body: JSON.stringify({ configuration_key: 'default' }),
            });
            await onCleared?.();
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'Unable to clear the saved SMTP password.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="workspace-stack" data-canonical-operation={ACCOUNT_EMAIL_CLEAR_PASSWORD_OPERATION_ID}>
            <button
                type="button"
                className="btn danger"
                disabled={busy}
                onClick={clear}
                data-canonical-operation={ACCOUNT_EMAIL_CLEAR_PASSWORD_OPERATION_ID}
                data-source-handler="ClearPasswordClickedAsync"
            >
                {busy ? '…' : (locale === 'ar' ? 'مسح كلمة المرور المحفوظة' : 'Clear saved password')}
            </button>
            {error ? <p role="alert">{error}</p> : null}
        </div>
    );
}
