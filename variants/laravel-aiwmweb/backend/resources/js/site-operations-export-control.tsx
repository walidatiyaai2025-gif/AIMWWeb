import React, { useEffect, useState } from 'react';
import { APP_DATA_GRID_CSV_EXPORT_OPERATION_ID, requestAppDataGridCsvExport } from './app-data-grid-csv-export-control';

export const SITE_OPERATIONS_EXPORT_OPERATION_ID = 'AIMW-BILL-582319B640';

type Locale = 'en' | 'ar';
type Row = Record<string, unknown>;

export const SITE_OPERATIONS_CSV_HEADERS = [
    'Time',
    'Site',
    'SiteId',
    'ExecutionJobId',
    'Operation',
    'Result',
    'DurationMs',
    'Records',
    'Message',
    'Details',
] as const;

function csvCell(value: unknown): string {
    const text = value == null ? '' : String(value);
    return /[",\n\r]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
}

function siteName(row: Row): string {
    if (typeof row.site_name === 'string') return row.site_name;
    const site = row.site;
    if (site && typeof site === 'object' && 'name' in site && typeof (site as { name?: unknown }).name === 'string') {
        return String((site as { name: string }).name);
    }
    return '';
}

function detailsText(value: unknown): string {
    if (value == null) return '';
    if (typeof value === 'string') return value;
    try {
        return JSON.stringify(value);
    } catch {
        return '';
    }
}

function localTimestamp(value: unknown): string {
    if (typeof value !== 'string' || !value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    const parts = [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
        ' ',
        String(date.getHours()).padStart(2, '0'),
        ':',
        String(date.getMinutes()).padStart(2, '0'),
        ':',
        String(date.getSeconds()).padStart(2, '0'),
    ];
    return parts.join('');
}

function durationMs(row: Row): string {
    const started = typeof row.started_at === 'string' ? Date.parse(row.started_at) : Number.NaN;
    const completed = typeof row.completed_at === 'string' ? Date.parse(row.completed_at) : Number.NaN;
    if (!Number.isFinite(started) || !Number.isFinite(completed)) return '';
    return String(Math.max(0, completed - started));
}

export function siteOperationsCsvRow(row: Row): readonly string[] {
    const status = String(row.status ?? '');
    const result = status === 'succeeded' ? 'Success' : status === 'failed' ? 'Failed' : status;
    return [
        localTimestamp(row.started_at),
        siteName(row),
        String(row.site_id ?? ''),
        String(row.execution_job_id ?? ''),
        String(row.operation ?? ''),
        result,
        durationMs(row),
        String(row.affected_records ?? ''),
        String(row.message ?? ''),
        detailsText(row.details),
    ];
}

export function buildSiteOperationsCsv(rows: Row[]): string {
    const lines = [SITE_OPERATIONS_CSV_HEADERS.map(csvCell).join(',')];
    for (const row of rows) {
        lines.push(siteOperationsCsvRow(row).map(csvCell).join(','));
    }
    return lines.join('\r\n');
}

export function siteOperationsCsvFileName(now = new Date()): string {
    const stamp = [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
        '-',
        String(now.getHours()).padStart(2, '0'),
        String(now.getMinutes()).padStart(2, '0'),
        String(now.getSeconds()).padStart(2, '0'),
    ].join('');
    return `site-operations-${stamp}.csv`;
}

export function SiteOperationsExportControl({
    rows,
    locale,
    resetToken = 0,
}: {
    rows: Row[];
    locale: Locale;
    resetToken?: number;
}) {
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setError(null);
    }, [resetToken]);
    const available = !exporting && rows.length > 0;
    const availability = available
        ? (locale === 'ar' ? 'تنزيل العمليات المعروضة حاليًا كملف CSV.' : 'Download the currently displayed operations as CSV.')
        : rows.length === 0
            ? (locale === 'ar' ? 'لا توجد عمليات معروضة لتصديرها.' : 'There are no displayed operations to export.')
            : (locale === 'ar' ? 'التصدير قيد التنفيذ.' : 'An export is already in progress.');

    const exportCsv = async () => {
        if (!available) return;
        setExporting(true);
        setError(null);
        let objectUrl: string | null = null;
        try {
            let csv = '';
            await requestAppDataGridCsvExport(
                rows,
                siteOperationsCsvRow,
                async (selectedRows) => {
                    csv = [
                        SITE_OPERATIONS_CSV_HEADERS.map(csvCell).join(','),
                        ...selectedRows.map((row) => row.map(csvCell).join(',')),
                    ].join('\r\n');
                },
            );
            const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' });
            objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download = siteOperationsCsvFileName();
            anchor.style.display = 'none';
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
        } catch {
            setError(locale === 'ar'
                ? 'تعذر بدء تنزيل ملف CSV. لم يتم عرض نجاح وهمي.'
                : 'The CSV download could not be started. No success was reported.');
        } finally {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            setExporting(false);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn"
                data-canonical-operation={SITE_OPERATIONS_EXPORT_OPERATION_ID}
                data-canonical-app-data-grid-csv-operation={APP_DATA_GRID_CSV_EXPORT_OPERATION_ID}
                disabled={!available}
                title={availability}
                onClick={() => { void exportCsv(); }}
            >
                {exporting
                    ? (locale === 'ar' ? 'جارٍ التصدير…' : 'Exporting…')
                    : (locale === 'ar' ? 'تصدير CSV' : 'Export CSV')}
            </button>
            {error ? <span className="alert error" role="alert">{error}</span> : null}
        </>
    );
}
