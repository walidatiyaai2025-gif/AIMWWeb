import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { ApiError, apiRequest, type FrontendContext } from './core';
import { LoadingState, StatePanel } from './components';
import { useLocale } from './i18n';

type MailConfigurationSummary = {
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

const canManageEmailSettings = (context: FrontendContext) =>
    context.permissions.includes('*') || context.permissions.includes('tenant.manage');

export function AccountEmailSettingsSummary({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const authorized = canManageEmailSettings(context);
    const endpoint = `/api/v1/tenants/${encodeURIComponent(context.tenant.slug)}/email/configuration`;
    const query = useQuery({
        queryKey: ['account-email-settings', context.tenant.slug],
        queryFn: () => apiRequest<MailConfigurationSummary>(endpoint),
        enabled: authorized,
    });

    if (!authorized) {
        return (
            <StatePanel tone="danger" title={locale === 'ar' ? 'الصلاحية مطلوبة' : 'Permission required'}>
                {locale === 'ar'
                    ? 'إعدادات البريد محمية بصلاحية إدارة الحساب. لم يتم طلب أو عرض بيانات إعداد البريد.'
                    : 'Email settings require tenant management permission. No mail configuration data was requested or rendered.'}
            </StatePanel>
        );
    }

    if (query.isLoading) return <LoadingState />;
    if (query.error) {
        const error = query.error instanceof ApiError ? query.error : null;
        return (
            <StatePanel tone="danger" title={locale === 'ar' ? 'تعذر تحميل إعدادات البريد' : 'Email settings unavailable'}>
                <p>{error?.message ?? (locale === 'ar' ? 'فشل طلب الإعدادات الموثوقة.' : 'The authoritative settings request failed.')}</p>
                {error ? <code>HTTP {error.status} · {error.code}</code> : null}
            </StatePanel>
        );
    }

    const configuration = query.data;
    if (!configuration?.configured) {
        return (
            <div className="workspace-stack">
                <section className="hero-panel">
                    <div>
                        <span className="workspace-kicker">EMAIL</span>
                        <h2>{locale === 'ar' ? 'إعدادات البريد' : 'Email settings'}</h2>
                        <p>{locale === 'ar' ? 'حالة الإرسال الموثوقة للحساب الحالي.' : 'Authoritative mail delivery state for the active tenant.'}</p>
                    </div>
                    <span className="tenant-badge">{context.tenant.name}</span>
                </section>
                <StatePanel title={locale === 'ar' ? 'البريد غير مهيأ' : 'Mail is not configured'}>
                    {locale === 'ar'
                        ? 'لم يُرجع الخادم إعداد SMTP محفوظًا. لا تنشئ الواجهة قيماً تجريبية.'
                        : 'The server returned no saved SMTP configuration. The interface does not synthesize demo values.'}
                </StatePanel>
            </div>
        );
    }

    return (
        <div className="workspace-stack">
            <section className="hero-panel">
                <div>
                    <span className="workspace-kicker">EMAIL</span>
                    <h2>{locale === 'ar' ? 'إعدادات البريد' : 'Email settings'}</h2>
                    <p>{locale === 'ar' ? 'عرض للتهيئة الفعلية دون كشف كلمة مرور SMTP.' : 'Read-only view of the active configuration without exposing the SMTP secret.'}</p>
                </div>
                <span className="tenant-badge">{context.tenant.name}</span>
            </section>
            <section className="panel" aria-label={locale === 'ar' ? 'تهيئة البريد الفعلية' : 'Authoritative mail configuration'}>
                <dl className="contract-details">
                    <div><dt>{locale === 'ar' ? 'الحالة' : 'Status'}</dt><dd>{configuration.enabled ? (locale === 'ar' ? 'مفعّل' : 'Enabled') : (locale === 'ar' ? 'معطّل' : 'Disabled')}</dd></div>
                    <div><dt>{locale === 'ar' ? 'النقل' : 'Transport'}</dt><dd>{configuration.transport ?? 'smtp'}</dd></div>
                    <div><dt>{locale === 'ar' ? 'الخادم' : 'Host'}</dt><dd>{configuration.host ?? '—'}</dd></div>
                    <div><dt>{locale === 'ar' ? 'المنفذ' : 'Port'}</dt><dd>{configuration.port ?? '—'}</dd></div>
                    <div><dt>{locale === 'ar' ? 'التشفير' : 'Encryption'}</dt><dd>{configuration.encryption ?? '—'}</dd></div>
                    <div><dt>{locale === 'ar' ? 'اسم المرسل' : 'From name'}</dt><dd>{configuration.from_name ?? '—'}</dd></div>
                    <div><dt>{locale === 'ar' ? 'بريد المرسل' : 'From address'}</dt><dd>{configuration.from_address ?? '—'}</dd></div>
                    <div><dt>{locale === 'ar' ? 'الرد إلى' : 'Reply to'}</dt><dd>{configuration.reply_to ?? '—'}</dd></div>
                    <div><dt>{locale === 'ar' ? 'بيانات الاعتماد' : 'Credentials'}</dt><dd>{configuration.has_secret ? (locale === 'ar' ? 'محفوظة بأمان' : 'Stored securely') : (locale === 'ar' ? 'غير محفوظة' : 'Not stored')}</dd></div>
                </dl>
            </section>
        </div>
    );
}
