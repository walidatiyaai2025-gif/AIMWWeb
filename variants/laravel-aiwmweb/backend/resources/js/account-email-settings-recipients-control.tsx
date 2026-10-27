import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const ACCOUNT_EMAIL_SAVE_EDIT_OPERATION_ID = 'AIMW-EMAI-10F3C44369';

export type AccountEmailRecipient = {
    id: number;
    email_address: string;
    display_name?: string | null;
    is_enabled: boolean;
};

function authorized(context: FrontendContext): boolean {
    return context.permissions.includes('*') || context.permissions.includes('tenant.manage');
}

function RecipientEditor({
    context,
    recipient,
    onSaved,
}: {
    context: FrontendContext;
    recipient: AccountEmailRecipient;
    onSaved: () => Promise<unknown> | unknown;
}) {
    const { locale } = useLocale();
    const [email, setEmail] = useState(recipient.email_address);
    const [displayName, setDisplayName] = useState(recipient.display_name ?? '');
    const [enabled, setEnabled] = useState(Boolean(recipient.is_enabled));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [saved, setSaved] = useState(false);

    useEffect(() => {
        setEmail(recipient.email_address);
        setDisplayName(recipient.display_name ?? '');
        setEnabled(Boolean(recipient.is_enabled));
        setError(null);
    }, [recipient.id, recipient.email_address, recipient.display_name, recipient.is_enabled]);

    const endpoint = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/email/recipients/${encodeURIComponent(String(recipient.id))}`;

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        if (busy) return;

        setBusy(true);
        setError(null);
        setSaved(false);
        try {
            await apiRequest<AccountEmailRecipient>(endpoint, {
                method: 'PATCH',
                body: JSON.stringify({
                    email_address: email,
                    display_name: displayName || null,
                    is_enabled: enabled,
                }),
            });
            await onSaved();
            setSaved(true);
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'Unable to update the dashboard recipient.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <form className="panel workspace-stack" onSubmit={submit} data-recipient-id={recipient.id}>
            <div className="grid-2">
                <label>
                    {locale === 'ar' ? 'البريد الإلكتروني' : 'Email address'}
                    <input
                        type="email"
                        required
                        maxLength={320}
                        aria-label={`Recipient email ${recipient.id}`}
                        value={email}
                        disabled={busy}
                        onChange={event => {
                            setSaved(false);
                            setEmail(event.target.value);
                        }}
                    />
                </label>
                <label>
                    {locale === 'ar' ? 'الاسم / الوصف' : 'Display name / label'}
                    <input
                        maxLength={120}
                        aria-label={`Recipient display name ${recipient.id}`}
                        value={displayName}
                        disabled={busy}
                        onChange={event => {
                            setSaved(false);
                            setDisplayName(event.target.value);
                        }}
                    />
                </label>
            </div>
            <label>
                <input
                    type="checkbox"
                    checked={enabled}
                    disabled={busy}
                    aria-label={`Recipient enabled ${recipient.id}`}
                    onChange={event => {
                        setSaved(false);
                        setEnabled(event.target.checked);
                    }}
                />
                {locale === 'ar' ? 'مفعل' : 'Enabled'}
            </label>
            <div className="toolbar-actions">
                <button
                    type="submit"
                    className="btn primary"
                    disabled={busy}
                    data-canonical-operation={ACCOUNT_EMAIL_SAVE_EDIT_OPERATION_ID}
                    data-source-handler="SaveEditClickedAsync"
                >
                    {busy ? '…' : (locale === 'ar' ? 'حفظ' : 'Save')}
                </button>
            </div>
            {saved ? <p role="status">{locale === 'ar' ? 'تم تحديث بريد الداشبورد.' : 'Dashboard recipient updated.'}</p> : null}
            {error ? <p role="alert">{error}</p> : null}
        </form>
    );
}

export function AccountEmailSettingsRecipientsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canManage = authorized(context);
    const endpoint = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/email/recipients`;
    const query = useQuery({
        queryKey: ['account-email-recipients', context.tenant.slug],
        queryFn: () => apiRequest<{ data: AccountEmailRecipient[] }>(endpoint),
        enabled: canManage,
    });

    if (!canManage) return null;

    if (query.isLoading) {
        return (
            <section className="panel" aria-busy="true" data-testid="account-email-recipients-loading">
                {locale === 'ar' ? 'جاري تحميل مستلمي الداشبورد...' : 'Loading dashboard recipients...'}
            </section>
        );
    }

    if (query.error) {
        return (
            <section className="panel empty-state" role="alert">
                <h3>{locale === 'ar' ? 'تعذر تحميل المستلمين' : 'Unable to load recipients'}</h3>
                <p>{query.error instanceof Error ? query.error.message : 'The authoritative recipient request failed.'}</p>
            </section>
        );
    }

    const recipients = Array.isArray(query.data?.data) ? query.data.data : [];

    return (
        <section className="workspace-stack" aria-label={locale === 'ar' ? 'مستلمو الداشبورد' : 'Dashboard recipients'}>
            <header className="panel-header">
                <div>
                    <span className="workspace-kicker">RECIPIENTS</span>
                    <h3>{locale === 'ar' ? 'مستلمو الداشبورد' : 'Dashboard recipients'}</h3>
                    <p>{locale === 'ar' ? 'تعديل المستلمين المحفوظين للحساب الحالي فقط.' : 'Edit persisted recipients for the active account only.'}</p>
                </div>
                <span className="count-badge">{recipients.length}</span>
            </header>
            {recipients.length === 0 ? (
                <section className="panel empty-state" data-testid="account-email-recipients-empty">
                    {locale === 'ar' ? 'لا توجد عناوين داشبورد محفوظة.' : 'No dashboard recipients are stored.'}
                </section>
            ) : recipients.map(recipient => (
                <RecipientEditor
                    key={recipient.id}
                    context={context}
                    recipient={recipient}
                    onSaved={async () => {
                        const refreshed = await query.refetch();
                        if (refreshed.error) throw refreshed.error;
                    }}
                />
            ))}
        </section>
    );
}
