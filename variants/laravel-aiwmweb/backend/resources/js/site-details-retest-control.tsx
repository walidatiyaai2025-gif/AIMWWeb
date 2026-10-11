import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { siteConnectionCenterEndpoints } from './site-connection-center-sync-control';

export const SITE_DETAILS_RETEST_OPERATION_ID = 'AIMW-BILL-D6B3460675';

type Snapshot = { site: { id: number }; connection: string; latest_diagnostic?: { classification: string } | null };
type History = { items: { id: number; operation: string; status: string; message: string }[] };

export function SiteDetailsRetestControl({ context }: { context: FrontendContext }) {
    const { siteId } = useParams();
    const { locale } = useLocale();
    const endpoints = siteConnectionCenterEndpoints(context.tenant.slug, siteId ?? '');
    const allowed = context.permissions.includes('*') || context.permissions.includes('connector.manage');
    if (!allowed || !endpoints) return null;

    return <AuthorizedRetest key={`${context.tenant.slug}:${siteId}`} endpoints={endpoints} siteId={Number(siteId)} arabic={locale === 'ar'} />;
}

function AuthorizedRetest({ endpoints, siteId, arabic }: {
    endpoints: NonNullable<ReturnType<typeof siteConnectionCenterEndpoints>>;
    siteId: number;
    arabic: boolean;
}) {
    const [snapshot, setSnapshot] = useState<Snapshot | null>(null);
    const [history, setHistory] = useState<History['items']>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [completed, setCompleted] = useState(false);

    const read = async () => {
        const [connection, operations] = await Promise.all([
            apiRequest<Snapshot>(endpoints.connection),
            apiRequest<History>(endpoints.operations),
        ]);
        if (Number(connection.site?.id) !== siteId) throw new Error('Connection state does not match the requested site.');
        return { connection, operations };
    };

    useEffect(() => {
        let active = true;
        read().then(({ connection, operations }) => {
            if (!active) return;
            setSnapshot(connection);
            setHistory(operations.items);
        }).catch((cause: unknown) => {
            if (active) setError(cause instanceof Error ? cause.message : 'Connection state unavailable.');
        });
        return () => { active = false; };
    }, [endpoints.connection, endpoints.operations, siteId]);

    const retest = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        setCompleted(false);
        try {
            await apiRequest(endpoints.connection.replace(/\/connection$/, '/retest'), { method: 'POST', body: JSON.stringify({}) });
            const { connection, operations } = await read();
            setSnapshot(connection);
            setHistory(operations.items);
            setCompleted(true);
        } catch (cause: unknown) {
            setError(cause instanceof Error ? cause.message : 'Connection test failed.');
        } finally {
            setBusy(false);
        }
    };

    return <section className="toolbar-panel site-details-retest" data-canonical-operation={SITE_DETAILS_RETEST_OPERATION_ID}>
        <button type="button" className="btn" disabled={busy} onClick={() => void retest()}>
            {busy ? (arabic ? 'جارٍ الاختبار…' : 'Testing…') : (arabic ? 'اختبار البيانات المحفوظة' : 'Test saved credentials')}
        </button>
        {error ? <p role="alert">{error}</p> : null}
        {completed ? <p role="status">{arabic ? 'اكتمل الاختبار. نتيجة الاتصال:' : 'Test completed. Connection result:'} {snapshot?.latest_diagnostic?.classification ?? snapshot?.connection}</p> : null}
        {history.length ? <ul aria-label={arabic ? 'سجل الاختبارات' : 'Test history'}>{history.map(item => <li key={item.id}>{item.operation}: {item.status} — {item.message}</li>)}</ul> : null}
    </section>;
}
