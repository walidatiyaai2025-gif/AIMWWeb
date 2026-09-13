import React, { useEffect, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const LOGS_CLOSE_DETAILS_OPERATION_ID = 'AIMW-AI-024BB0971B';

type LogRow = Record<string, unknown>;

type LoadState = 'idle' | 'loading' | 'ready' | 'error';

function isRecord(value: unknown): value is LogRow {
    return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function normalizeLogs(payload: unknown): LogRow[] {
    if (Array.isArray(payload)) return payload.filter(isRecord);
    if (!isRecord(payload)) return [];

    const data = payload.data;
    if (Array.isArray(data)) return data.filter(isRecord);
    if (isRecord(data) && Array.isArray(data.data)) return data.data.filter(isRecord);

    return [];
}

function rowIdentity(row: LogRow, index: number): string {
    const candidate = row.id ?? row.line ?? row.number ?? row.sequence ?? index + 1;
    return String(candidate);
}

function rowSummary(row: LogRow): string {
    const candidate = row.message ?? row.text ?? row.summary ?? row.type ?? row.level ?? 'Log entry';
    return String(candidate);
}

export function LogsCloseDetailsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [rows, setRows] = useState<LogRow[]>([]);
    const [selected, setSelected] = useState<LogRow | null>(null);
    const [state, setState] = useState<LoadState>('idle');
    const endpoint = context.api.logs;
    const expectedEndpoint = `/tenants/${context.tenant.slug}/admin/logs`;
    const canRead = context.permissions.includes('operations.manage') && context.permissions.includes('diagnostics.view');

    useEffect(() => {
        let cancelled = false;

        setSelected(null);
        if (!canRead || !endpoint || endpoint !== expectedEndpoint) {
            setRows([]);
            setState('idle');
            return () => { cancelled = true; };
        }

        setState('loading');
        apiRequest<unknown>(endpoint)
            .then((payload) => {
                if (cancelled) return;
                const next = normalizeLogs(payload);
                setRows(next);
                setState(next.length ? 'ready' : 'idle');
            })
            .catch(() => {
                if (cancelled) return;
                setRows([]);
                setState('error');
            });

        return () => { cancelled = true; };
    }, [canRead, endpoint, expectedEndpoint]);

    if (state !== 'ready' || rows.length === 0) return null;

    return (
        <section className="panel logs-detail-inspector" aria-label={locale === 'ar' ? 'تفاصيل السجلات' : 'Log details'}>
            <header className="logs-toolbar">
                <div>
                    <span className="workspace-kicker">DIAGNOSTICS</span>
                    <strong>{locale === 'ar' ? 'فحص تفاصيل السجل' : 'Inspect log details'}</strong>
                </div>
            </header>

            <div className="toolbar-panel" aria-label={locale === 'ar' ? 'اختيار سجل' : 'Log detail selection'}>
                {rows.slice(0, 20).map((row, index) => {
                    const identity = rowIdentity(row, index);
                    return (
                        <button
                            type="button"
                            className="btn"
                            key={`${identity}-${index}`}
                            aria-label={locale === 'ar' ? `فحص تفاصيل السجل ${identity}` : `Inspect log detail ${identity}`}
                            onClick={() => setSelected(row)}
                        >
                            {rowSummary(row)}
                        </button>
                    );
                })}
            </div>

            {selected ? (
                <section className="panel log-details" role="region" aria-label={locale === 'ar' ? 'تفاصيل السطر' : 'Line details'}>
                    <header>
                        <div>
                            <span className="workspace-kicker">LOG DETAIL</span>
                            <strong>{rowSummary(selected)}</strong>
                        </div>
                        <button
                            type="button"
                            className="btn"
                            aria-label={locale === 'ar' ? 'إغلاق تفاصيل السجل' : 'Close log details'}
                            data-canonical-operation={LOGS_CLOSE_DETAILS_OPERATION_ID}
                            onClick={() => setSelected(null)}
                        >
                            ×
                        </button>
                    </header>
                    <pre>{JSON.stringify(selected, null, 2)}</pre>
                </section>
            ) : null}
        </section>
    );
}
