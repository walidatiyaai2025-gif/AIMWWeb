import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const EMAIL_SCHEDULE_CREATE_OPERATION_ID = 'AIMW-BILL-7101AC9489';
export const EMAIL_SCHEDULE_CREATE_SOURCE_OPERATION_KEY =
    'visible:src/AIWordPressManager.Web/Components/Pages/EmailSchedules.razor:/email/schedules:@(L.IsArabic ?:CreateClicked';

export type EmailScheduleDraft = {
    scope: 'Account' | 'Site';
    site_id: string;
    frequency: 'Hourly' | 'Daily' | 'Weekly' | 'Monthly';
    time_of_day: string;
    weekday: number;
    month_day: number;
    timezone_id: string;
    culture: 'en' | 'ar';
    retry_count: number;
    retry_delay_minutes: number;
    enabled: boolean;
};

export const defaultEmailScheduleDraft = (): EmailScheduleDraft => ({
    scope: 'Account',
    site_id: '',
    frequency: 'Daily',
    time_of_day: '08:00',
    weekday: 1,
    month_day: 1,
    timezone_id: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
    culture: 'en',
    retry_count: 3,
    retry_delay_minutes: 5,
    enabled: true,
});

export function buildEmailSchedulePayload(draft: EmailScheduleDraft): Record<string, unknown> {
    return {
        scope: draft.scope,
        site_id: draft.scope === 'Site' ? Number(draft.site_id) : null,
        frequency: draft.frequency,
        time_of_day: draft.time_of_day,
        weekday: draft.frequency === 'Weekly' ? draft.weekday : null,
        month_day: draft.frequency === 'Monthly' ? draft.month_day : null,
        timezone_id: draft.timezone_id,
        culture: draft.culture,
        retry_count: draft.retry_count,
        retry_delay_minutes: draft.retry_delay_minutes,
        enabled: draft.enabled,
    };
}

function authorized(context: FrontendContext): boolean {
    return context.permissions.includes('*') || context.permissions.includes('operations.manage');
}

export function EmailSchedulesCreateControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const endpoint = context.api['email-schedules'];
    const [draft, setDraft] = useState<EmailScheduleDraft>(defaultEmailScheduleDraft);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!authorized(context) || !endpoint) return null;

    const submit = async (event: React.FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await apiRequest(endpoint, {
                method: 'POST',
                headers: { 'Idempotency-Key': crypto.randomUUID() },
                body: buildEmailSchedulePayload(draft),
            });
            await apiRequest(endpoint);
            window.location.reload();
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : 'Schedule creation failed.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="panel" data-canonical-operation={EMAIL_SCHEDULE_CREATE_OPERATION_ID}
            data-source-operation-key={EMAIL_SCHEDULE_CREATE_SOURCE_OPERATION_KEY}>
            <form className="schedule-form" onSubmit={submit}>
                <label><span>{locale === 'ar' ? 'النطاق' : 'Scope'}</span>
                    <select value={draft.scope} disabled={busy} onChange={e => setDraft({...draft, scope: e.target.value as EmailScheduleDraft['scope']})}>
                        <option value="Account">{locale === 'ar' ? 'الداشبورد / الحساب' : 'Dashboard / account'}</option>
                        <option value="Site">{locale === 'ar' ? 'موقع WordPress' : 'WordPress site'}</option>
                    </select>
                </label>
                {draft.scope === 'Site' ? <label><span>{locale === 'ar' ? 'معرف الموقع' : 'Site ID'}</span><input required type="number" min="1" value={draft.site_id} disabled={busy} onChange={e => setDraft({...draft, site_id:e.target.value})} /></label> : null}
                <label><span>{locale === 'ar' ? 'التكرار' : 'Frequency'}</span>
                    <select value={draft.frequency} disabled={busy} onChange={e => setDraft({...draft, frequency:e.target.value as EmailScheduleDraft['frequency']})}>
                        {['Hourly','Daily','Weekly','Monthly'].map(value => <option key={value} value={value}>{value}</option>)}
                    </select>
                </label>
                <label><span>{locale === 'ar' ? 'الوقت المحلي' : 'Local time'}</span><input required type="time" value={draft.time_of_day} disabled={busy} onChange={e => setDraft({...draft,time_of_day:e.target.value})}/></label>
                <label><span>{locale === 'ar' ? 'المنطقة الزمنية' : 'Timezone'}</span><input required value={draft.timezone_id} disabled={busy} onChange={e => setDraft({...draft,timezone_id:e.target.value})}/></label>
                <label><span>{locale === 'ar' ? 'لغة الرسالة' : 'Email language'}</span><select value={draft.culture} disabled={busy} onChange={e => setDraft({...draft,culture:e.target.value as 'en'|'ar'})}><option value="en">English</option><option value="ar">العربية</option></select></label>
                <label><span>{locale === 'ar' ? 'إعادة المحاولة' : 'Retries'}</span><input type="number" min="0" max="10" value={draft.retry_count} disabled={busy} onChange={e=>setDraft({...draft,retry_count:Number(e.target.value)})}/></label>
                <label><span>{locale === 'ar' ? 'تأخير المحاولة' : 'Retry delay (minutes)'}</span><input type="number" min="1" max="1440" value={draft.retry_delay_minutes} disabled={busy} onChange={e=>setDraft({...draft,retry_delay_minutes:Number(e.target.value)})}/></label>
                <label><input type="checkbox" checked={draft.enabled} disabled={busy} onChange={e=>setDraft({...draft,enabled:e.target.checked})}/> {locale === 'ar' ? 'تفعيل فورًا' : 'Enable immediately'}</label>
                <button type="submit" className="btn primary" disabled={busy}>{busy ? '…' : (locale === 'ar' ? 'إنشاء الجدول' : 'Create schedule')}</button>
                {error ? <p role="alert">{error}</p> : null}
            </form>
        </section>
    );
}
