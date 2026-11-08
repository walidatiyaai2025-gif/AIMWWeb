import React, { useEffect, useState } from 'react';
import { apiRequest, tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const LOGS_CLEAR_FILTERS_OPERATION_ID = 'AIMW-CONT-83908F2D7C';
export const LOGS_COPY_VISIBLE_OPERATION_ID = 'AIMW-BILL-2138CD95B2';

type LogsPayload = {
    data?: Array<Record<string, unknown>>;
};

// Clipboard text is shareable and must not trust arbitrary historic log messages,
// even when the API has already applied its own legacy-record redaction.
const WITHHELD_LOG_TEXT = '[withheld: unsafe log text]';
function safeCopiedLogLevel(value: unknown): string {
    return typeof value === 'string' && /^[a-z]{1,20}$/i.test(value) ? value : 'Unknown';
}
function safeCopiedLogMessage(value: unknown): string {
    if (typeof value !== 'string' || !value.trim() || value.length > 240) return WITHHELD_LOG_TEXT;
    // Reject entire opaque values: partial regex replacements can leave credentials,
    // signed query strings, JSON bodies or multiline trace fragments behind.
    if (/[\x00-\x1f\x7f<>{}=?&]/.test(value)
        || value.includes('[') || value.includes(']')
        || /https?:\/\/|www\./i.test(value)
        || /\b(?:bearer|authorization|password|passwd|token|secret|api[_-]?key|credential|session|cookie|private[_-]?key|access[_-]?key|sqlstate|traceback|stack trace|exception)\b/i.test(value)) {
        return WITHHELD_LOG_TEXT;
    }
    return value;
}

export function formatVisibleLogs(rows: Array<Record<string, unknown>>): string {
    return rows.slice(0, 100).map((row) =>
        `[${safeCopiedLogLevel(row.level)}] ${safeCopiedLogMessage(row.message)}`,
    ).join('\n');
}

export function LogsClearFiltersControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/module/logs');
    const expectedLogsApi = tenantUrl(context.tenant.slug, '/admin/logs');
    const hasPermission = context.permissions.includes('*') || (
        context.permissions.includes('operations.manage')
        && context.permissions.includes('diagnostics.view')
    );
    const trustedLogsApi = hasPermission && context.api.logs === expectedLogsApi;
    const [workspaceReady, setWorkspaceReady] = useState(false);
    const [copying, setCopying] = useState(false);
    const [feedback, setFeedback] = useState<{ tone: 'success' | 'error' | 'info'; message: string } | null>(null);

    useEffect(() => {
        const update = () => setWorkspaceReady(Boolean(document.getElementById('search-logs')));
        update();
        const observer = new MutationObserver(update);
        observer.observe(document.body, { childList: true, subtree: true });
        return () => observer.disconnect();
    }, []);

    const copyVisible = async (): Promise<void> => {
        if (!trustedLogsApi || !workspaceReady || copying) return;

        const searchInput = document.getElementById('search-logs') as HTMLInputElement | null;
        if (!searchInput) return;

        const url = new URL(expectedLogsApi, window.location.origin);
        const search = searchInput.value.trim();
        if (search) url.searchParams.set('search', search);

        setCopying(true);
        setFeedback(null);
        try {
            const payload = await apiRequest<LogsPayload>(`${url.pathname}${url.search}`);
            const rows = Array.isArray(payload.data) ? payload.data : [];
            if (rows.length === 0) {
                setFeedback({
                    tone: 'info',
                    message: locale === 'ar' ? 'لا توجد نتائج ظاهرة لنسخها.' : 'No visible results to copy.',
                });
                return;
            }

            const clipboard = navigator.clipboard;
            if (!clipboard || typeof clipboard.writeText !== 'function') {
                throw new Error('Clipboard API unavailable');
            }

            await clipboard.writeText(formatVisibleLogs(rows));
            setFeedback({
                tone: 'success',
                message: locale === 'ar' ? 'تم نسخ النتائج الظاهرة.' : 'Visible results copied.',
            });
        } catch {
            setFeedback({
                tone: 'error',
                message: locale === 'ar' ? 'تعذر نسخ النتائج الظاهرة.' : 'Visible results could not be copied.',
            });
        } finally {
            setCopying(false);
        }
    };

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'أدوات السجلات' : 'Log controls'}>
            <a
                className="btn"
                href={target}
                data-canonical-operation={LOGS_CLEAR_FILTERS_OPERATION_ID}
            >
                {locale === 'ar' ? 'مسح الفلاتر' : 'Clear filters'}
            </a>
            <button
                type="button"
                className="btn"
                data-canonical-operation={LOGS_COPY_VISIBLE_OPERATION_ID}
                disabled={!trustedLogsApi || !workspaceReady || copying}
                aria-busy={copying ? 'true' : 'false'}
                onClick={() => void copyVisible()}
            >
                {copying
                    ? (locale === 'ar' ? 'جارٍ النسخ…' : 'Copying…')
                    : (locale === 'ar' ? 'نسخ النتائج' : 'Copy results')}
            </button>
            {feedback ? (
                <span
                    role={feedback.tone === 'error' ? 'alert' : 'status'}
                    className={`copy-feedback copy-feedback-${feedback.tone}`}
                >
                    {feedback.message}
                </span>
            ) : null}
        </section>
    );
}
