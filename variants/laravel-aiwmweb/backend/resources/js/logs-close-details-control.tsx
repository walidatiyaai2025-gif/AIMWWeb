import React, { useEffect, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const LOGS_CLOSE_DETAILS_OPERATION_ID = 'AIMW-AI-024BB0971B';
export const LOGS_COPY_SELECTED_OPERATION_ID = 'AIMW-BILL-77E7F0D972';

type LogRow = Record<string, unknown>;
type LoadState = 'idle' | 'loading' | 'ready' | 'error';
type CopyState = 'idle' | 'copying' | 'success' | 'error';

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

export function extractSelectedLogErrorCode(text: string): string {
    const explicit = text.match(/\bERR[-_: ]?[A-Z0-9]{4,}(?:[-_][A-Z0-9]+)*\b/i);
    if (explicit?.[0]) return explicit[0];
    const structured = text.match(/\b[A-Z]{2,}[A-Z0-9]{2,}(?:[-_][A-Z0-9]+)+\b/);
    return structured?.[0] ?? '-';
}

export function formatSelectedLog(row: LogRow): string {
    const file = String(row.file ?? row.file_name ?? row.source ?? row.log_file ?? '');
    const line = String(row.line ?? row.number ?? row.id ?? '-');
    const level = String(row.level ?? '');
    const text = String(row.message ?? row.text ?? '');
    return `File: ${file}\nLine: ${line}\nLevel: ${level}\nError code: ${extractSelectedLogErrorCode(text)}\n\n${text}`;
}

export function LogsCloseDetailsControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const [rows, setRows] = useState<LogRow[]>([]);
    const [selected, setSelected] = useState<LogRow | null>(null);
    const [state, setState] = useState<LoadState>('idle');
    const [copyState, setCopyState] = useState<CopyState>('idle');
    const endpoint = context.api.logs;
    const expectedEndpoint = `/tenants/${context.tenant.slug}/admin/logs`;
    const canRead = context.permissions.includes('operations.manage') && context.permissions.includes('diagnostics.view');

    useEffect(() => {
        let cancelled = false;
        setSelected(null);
        setCopyState('idle');
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

    const copySelected = async (): Promise<void> => {
        if (!selected || copyState === 'copying') return;
        setCopyState('copying');
        try {
            const clipboard = navigator.clipboard;
            if (!clipboard || typeof clipboard.writeText !== 'function') {
                throw new Error('Clipboard API unavailable');
            }
            await clipboard.writeText(formatSelectedLog(selected));
            setCopyState('success');
        } catch {
            setCopyState('error');
        }
    };

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
                            onClick={() => { setSelected(row); setCopyState('idle'); }}
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
                        <div className="toolbar">
                            <button
                                type="button"
                                className="btn primary"
                                aria-label={locale === 'ar' ? 'نسخ تفاصيل الخطأ' : 'Copy error details'}
                                data-canonical-operation={LOGS_COPY_SELECTED_OPERATION_ID}
                                disabled={copyState === 'copying'}
                                onClick={() => void copySelected()}
                            >
                                {copyState === 'copying' ? (locale === 'ar' ? 'جارٍ النسخ…' : 'Copying…') : (locale === 'ar' ? 'نسخ التفاصيل' : 'Copy details')}
                            </button>
                            <button
                                type="button"
                                className="btn"
                                aria-label={locale === 'ar' ? 'إغلاق تفاصيل السجل' : 'Close log details'}
                                data-canonical-operation={LOGS_CLOSE_DETAILS_OPERATION_ID}
                                onClick={() => setSelected(null)}
                            >
                                ×
                            </button>
                        </div>
                    </header>
                    {copyState === 'success' ? <p role="status">{locale === 'ar' ? 'تم نسخ تفاصيل الخطأ.' : 'Error details copied.'}</p> : null}
                    {copyState === 'error' ? <p role="alert">{locale === 'ar' ? 'تعذر نسخ تفاصيل الخطأ.' : 'Error details could not be copied.'}</p> : null}
                    <pre>{JSON.stringify(selected, null, 2)}</pre>
                </section>
            ) : null}
        </section>
    );
}
