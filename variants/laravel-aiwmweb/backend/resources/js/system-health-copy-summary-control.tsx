import React, { useRef, useState } from 'react';
import type { FrontendContext } from './core';
import { useLocale } from './i18n';

export const SYSTEM_HEALTH_COPY_SUMMARY_OPERATION_ID = 'AIMW-BILL-48E69F42EB';
export type SystemHealthSnapshot = {
    tenant: string;
    status: string;
    checks: Record<string, { status: string }>;
};

// Export only the status contract; never serialize arbitrary diagnostic payloads.
export function buildSystemHealthSummary(snapshot: SystemHealthSnapshot): string | null {
    if (!['ready', 'not_ready'].includes(snapshot.status)) return null;
    const names = ['app', 'database', 'redis', 'storage', 'queue', 'scheduler'];
    if (!snapshot.checks || names.some((name) => !['ok', 'unavailable'].includes(snapshot.checks[name]?.status))) return null;
    return [
        'AI WordPress Manager - System Health Summary',
        `Overall: ${snapshot.status === 'ready' ? 'Healthy' : 'Attention required'}`,
        ...names.map((name) => `${name} | ${snapshot.checks[name].status}`),
    ].join('\n');
}

export function SystemHealthCopySummaryControl({ context, snapshot, loading = false }: {
    context: FrontendContext;
    snapshot?: SystemHealthSnapshot;
    loading?: boolean;
}) {
    const { locale } = useLocale();
    const pending = useRef(false);
    const [copying, setCopying] = useState(false);
    const [result, setResult] = useState<'copied' | 'failed' | null>(null);
    const summary = snapshot && snapshot.tenant === context.tenant.slug
        && context.tenants.some((tenant) => tenant.slug === context.tenant.slug)
        && context.permissions.includes('tenant.view') && context.permissions.includes('diagnostics.view')
        ? buildSystemHealthSummary(snapshot) : null;

    if (loading || summary === null) return null;

    async function copySummaryAsync() {
        if (pending.current || summary === null) return;
        pending.current = true;
        setCopying(true);
        setResult(null);
        try {
            if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
            await navigator.clipboard.writeText(summary);
            setResult('copied');
        } catch {
            setResult('failed');
        } finally {
            pending.current = false;
            setCopying(false);
        }
    }

    return <div>
        <button type="button" disabled={copying} onClick={() => void copySummaryAsync()}
            data-canonical-operation={SYSTEM_HEALTH_COPY_SUMMARY_OPERATION_ID}>
            {locale === 'ar' ? 'نسخ ملخص صحة النظام' : 'Copy system-health summary'}
        </button>
        {result === 'copied' && <p role="status">{locale === 'ar' ? 'تم نسخ ملخص صحة النظام.' : 'System-health summary copied.'}</p>}
        {result === 'failed' && <p role="alert">{locale === 'ar' ? 'تعذر نسخ الملخص. تحقق من صلاحية الحافظة وحاول مجددًا.' : 'The summary could not be copied. Check clipboard permission and try again.'}</p>}
    </div>;
}
