import React, { useEffect, useState } from 'react';
import { apiRequest } from './core';
import { useLocale } from './i18n';

type LiveBatch = {
    id: string; site_id: number | null; state: string; total: number; completed: number;
    pending: number; running: number; paused: number; failed: number;
    needs_reconciliation: number; resume_after: string | null; updated_at: string;
};
type LiveReport = {
    runtime: { ai_enabled: boolean; cron_enabled: boolean; preflight_passed: boolean; queue_driver: string };
    batches: LiveBatch[];
};
const activeStates = new Set(['pending', 'queued', 'running']);

export function AiOperationsLiveReport({ tenant }: { tenant: string }) {
    const { locale } = useLocale();
    const ar = locale === 'ar';
    const [visible, setVisible] = useState(false);
    const [report, setReport] = useState<LiveReport | null>(null);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        if (!visible) return;
        let stopped = false;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const poll = async () => {
            // No background polling from hidden tabs; never dispatches AI work.
            if (document.visibilityState === 'hidden') {
                timer = setTimeout(poll, 30000);
                return;
            }
            setLoading(true);
            let nextInterval = 30000;
            try {
                const response = await apiRequest<{ data: LiveReport }>(
                    `/api/tenants/${encodeURIComponent(tenant)}/ai-batches`,
                );
                if (stopped) return;
                setReport(response.data);
                setError('');
                if (response.data.batches.some((batch) => activeStates.has(batch.state))) nextInterval = 8000;
            } catch (reason) {
                if (stopped) return;
                setError(reason instanceof Error ? reason.message : String(reason));
            } finally {
                if (!stopped) {
                    setLoading(false);
                    timer = setTimeout(poll, nextInterval);
                }
            }
        };
        void poll();
        return () => { stopped = true; clearTimeout(timer); };
    }, [tenant, visible]);

    return <section className="panel workspace-stack" aria-label={ar ? 'تقرير تشغيل الذكاء الاصطناعي' : 'AI operations report'}>
        <div className="toolbar-actions">
            <button type="button" className="btn" aria-expanded={visible} onClick={() => setVisible(!visible)}>
                {visible ? (ar ? 'إخفاء تقرير AI المباشر' : 'Hide live AI report') :
                    (ar ? 'عرض تقرير AI المباشر' : 'Show live AI report')}
            </button>
        </div>
        {visible && <>
            <p>{ar ? 'تقرير قراءة فقط من قاعدة البيانات، دون بدء طلبات توليد. تحديث كل 8 ثوانٍ أثناء التشغيل.' :
                'Read-only database report; no generation requests. Refreshes every 8 seconds while active.'}</p>
            {loading && <span role="status">{ar ? 'تحديث حالة المهام…' : 'Refreshing AI task status…'}</span>}
            {error && <p role="alert">{error}</p>}
            {report && <>
                <div className="status-grid">
                    <span>{ar ? 'محرك AI' : 'AI execution'}: {report.runtime.ai_enabled ? (ar ? 'مفعّل' : 'enabled') : (ar ? 'موقوف' : 'disabled')}</span>
                    <span>{ar ? 'الفحص المسبق' : 'Preflight'}: {report.runtime.preflight_passed ? (ar ? 'نجح' : 'passed') : (ar ? 'لم يُعتمد' : 'not approved')}</span>
                    <span>{ar ? 'مجدول cPanel' : 'cPanel cron'}: {report.runtime.cron_enabled ? (ar ? 'مفعّل' : 'enabled') : (ar ? 'موقوف' : 'disabled')}</span>
                    <span>{ar ? 'الطابور' : 'Queue'}: {report.runtime.queue_driver}</span>
                </div>
                {report.runtime.queue_driver === 'sync' && <p role="alert">
                    {ar ? 'تحذير: الطابور sync ينفّذ وظائف Laravel داخل طلب الويب. استخدم database مع عامل محدود بعد فحص الاستضافة.' :
                        'Warning: sync queue runs Laravel jobs inside HTTP requests. Use a bounded database worker only after hosting preflight.'}
                </p>}
                {!report.batches.length ? <p role="status">{ar ? 'لا توجد دفعات AI محفوظة لهذا المستخدم.' : 'No saved AI batches for this user.'}</p> :
                    <div className="data-table-scroll">
                        <table>
                            <thead><tr>
                                <th>{ar ? 'الدفعة' : 'Batch'}</th>
                                <th>{ar ? 'الحالة' : 'State'}</th>
                                <th>{ar ? 'مكتمل/إجمالي' : 'Done/Total'}</th>
                                <th>{ar ? 'يعمل' : 'Running'}</th>
                                <th>{ar ? 'منتظر' : 'Pending'}</th>
                                <th>{ar ? 'متوقف' : 'Paused'}</th>
                                <th>{ar ? 'فشل' : 'Failed'}</th>
                                <th>{ar ? 'مراجعة' : 'Reconcile'}</th>
                                <th>{ar ? 'آخر تحديث' : 'Updated'}</th>
                            </tr></thead>
                            <tbody>{report.batches.map(batch => <tr key={batch.id}>
                                <td><code>{batch.id.slice(0, 8)}</code></td>
                                <td>{batch.state}</td>
                                <td>{batch.completed}/{batch.total}</td>
                                <td>{batch.running}</td>
                                <td>{batch.pending}</td>
                                <td>{batch.paused}</td>
                                <td>{batch.failed}</td>
                                <td>{batch.needs_reconciliation}</td>
                                <td>{batch.updated_at}</td>
                            </tr>)}</tbody>
                        </table>
                    </div>}
            </>}
        </>}
    </section>;
}
