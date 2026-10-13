import React, { useEffect, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ACCOUNT_EMAIL_SAVE_PROFILE_OPERATION_ID = 'AIMW-EMAI-00CC1272F6';

export type AccountMailConfiguration = {
    configuration_key?: string;
    configured?: boolean;
    has_secret?: boolean;
    transport?: string | null;
    host?: string | null;
    port?: number | null;
    encryption?: string | null;
    username?: string | null;
    from_address?: string | null;
    from_name?: string | null;
    reply_to?: string | null;
    enabled?: boolean;
    timeout_seconds?: number | null;
    max_attempts?: number | null;
};

type Draft = {
    host: string;
    port: number;
    username: string;
    secret: string;
    fromAddress: string;
    fromName: string;
    replyTo: string;
    enableTls: boolean;
    enabled: boolean;
    timeoutSeconds: number;
    maxAttempts: number;
};

const draftFrom = (value?: AccountMailConfiguration): Draft => ({
    host: value?.host ?? '',
    port: value?.port ?? 587,
    username: value?.username ?? '',
    secret: '',
    fromAddress: value?.from_address ?? '',
    fromName: value?.from_name ?? '',
    replyTo: value?.reply_to ?? '',
    enableTls: value?.encryption === 'tls' || value?.encryption === 'ssl' || !value?.configured,
    enabled: Boolean(value?.enabled),
    timeoutSeconds: value?.timeout_seconds ?? 20,
    maxAttempts: value?.max_attempts ?? 4,
});

function authorized(context: FrontendContext): boolean {
    return context.permissions.includes('*') || context.permissions.includes('tenant.manage');
}

export function AccountEmailSettingsProfileForm({
    context,
    configuration,
    onSaved,
}: {
    context: FrontendContext;
    configuration?: AccountMailConfiguration;
    onSaved?: () => Promise<unknown> | unknown;
}) {
    const { locale } = useLocale();
    const [draft, setDraft] = useState<Draft>(() => draftFrom(configuration));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        setDraft(draftFrom(configuration));
    }, [
        configuration?.configuration_key,
        configuration?.host,
        configuration?.port,
        configuration?.username,
        configuration?.from_address,
        configuration?.from_name,
        configuration?.reply_to,
        configuration?.encryption,
        configuration?.enabled,
        configuration?.timeout_seconds,
        configuration?.max_attempts,
        configuration?.has_secret,
    ]);

    if (!authorized(context)) return null;

    const endpoint = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/email/configuration`;

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        setError(null);
        setSaved(false);

        try {
            await apiRequest<AccountMailConfiguration>(endpoint, {
                method: 'PUT',
                body: JSON.stringify({
                    configuration_key: configuration?.configuration_key ?? 'default',
                    transport: 'smtp',
                    host: draft.host,
                    port: draft.port,
                    encryption: draft.enableTls ? 'tls' : null,
                    username: draft.username || null,
                    secret: draft.secret || null,
                    from_address: draft.fromAddress,
                    from_name: draft.fromName,
                    reply_to: draft.replyTo || null,
                    enabled: draft.enabled,
                    timeout_seconds: draft.timeoutSeconds,
                    max_attempts: draft.maxAttempts,
                }),
            });
            setDraft(current => ({ ...current, secret: '' }));
            setSaved(true);
            await onSaved?.();
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'Unable to save the mail profile.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="panel" data-canonical-operation={ACCOUNT_EMAIL_SAVE_PROFILE_OPERATION_ID}>
            <h3>{locale === 'ar' ? 'إعداد الإرسال العام' : 'Account outbound mail profile'}</h3>
            <form onSubmit={submit} className="workspace-stack">
                <div className="grid-2">
                    <label>{locale === 'ar' ? 'خادم SMTP' : 'SMTP Host'}
                        <input aria-label="SMTP Host" required maxLength={255} value={draft.host} disabled={busy}
                            onChange={event => setDraft({ ...draft, host: event.target.value })} />
                    </label>
                    <label>{locale === 'ar' ? 'منفذ SMTP' : 'SMTP Port'}
                        <input aria-label="SMTP Port" required type="number" min={1} max={65535} value={draft.port} disabled={busy}
                            onChange={event => setDraft({ ...draft, port: Number(event.target.value) })} />
                    </label>
                    <label>{locale === 'ar' ? 'اسم المستخدم' : 'Username'}
                        <input aria-label="SMTP username" maxLength={255} value={draft.username} disabled={busy}
                            onChange={event => setDraft({ ...draft, username: event.target.value })} />
                    </label>
                    <label>{locale === 'ar' ? 'كلمة المرور' : 'Password'}
                        <input aria-label="SMTP password" type="password" autoComplete="new-password" maxLength={4000}
                            value={draft.secret} disabled={busy}
                            onChange={event => setDraft({ ...draft, secret: event.target.value })} />
                        <small>{configuration?.has_secret
                            ? (locale === 'ar' ? 'يوجد سر محفوظ. اترك الحقل فارغًا للاحتفاظ به.' : 'A secret is stored. Leave blank to keep it.')
                            : (locale === 'ar' ? 'لا يوجد سر محفوظ.' : 'No password is currently stored.')}</small>
                    </label>
                    <label>{locale === 'ar' ? 'بريد المرسل' : 'From address'}
                        <input aria-label="From address" required type="email" maxLength={255} value={draft.fromAddress} disabled={busy}
                            onChange={event => setDraft({ ...draft, fromAddress: event.target.value })} />
                    </label>
                    <label>{locale === 'ar' ? 'اسم المرسل' : 'From name'}
                        <input aria-label="From name" required maxLength={255} value={draft.fromName} disabled={busy}
                            onChange={event => setDraft({ ...draft, fromName: event.target.value })} />
                    </label>
                    <label>{locale === 'ar' ? 'الرد إلى' : 'Reply-to address'}
                        <input aria-label="Reply-to address" type="email" maxLength={255} value={draft.replyTo} disabled={busy}
                            onChange={event => setDraft({ ...draft, replyTo: event.target.value })} />
                    </label>
                    <label>{locale === 'ar' ? 'مهلة الاتصال' : 'Timeout seconds'}
                        <input aria-label="Timeout seconds" type="number" min={2} max={120} value={draft.timeoutSeconds} disabled={busy}
                            onChange={event => setDraft({ ...draft, timeoutSeconds: Number(event.target.value) })} />
                    </label>
                    <label>{locale === 'ar' ? 'أقصى محاولات' : 'Max attempts'}
                        <input aria-label="Max attempts" type="number" min={1} max={5} value={draft.maxAttempts} disabled={busy}
                            onChange={event => setDraft({ ...draft, maxAttempts: Number(event.target.value) })} />
                    </label>
                </div>
                <label><input type="checkbox" checked={draft.enableTls} disabled={busy}
                    onChange={event => setDraft({ ...draft, enableTls: event.target.checked })} /> TLS / SSL</label>
                <label><input type="checkbox" checked={draft.enabled} disabled={busy}
                    onChange={event => setDraft({ ...draft, enabled: event.target.checked })} />
                    {locale === 'ar' ? 'تفعيل الإرسال العام للحساب' : 'Enable account outbound email'}</label>
                <button type="submit" className="btn primary" disabled={busy}
                    data-canonical-operation={ACCOUNT_EMAIL_SAVE_PROFILE_OPERATION_ID}
                    data-source-handler="SaveProfileClickedAsync">
                    {busy ? '…' : (locale === 'ar' ? 'حفظ إعداد البريد' : 'Save mail profile')}
                </button>
                {saved ? <p role="status">{locale === 'ar' ? 'تم حفظ إعداد البريد.' : 'Mail profile saved.'}</p> : null}
                {error ? <p role="alert">{error}</p> : null}
            </form>
        </section>
    );
}
