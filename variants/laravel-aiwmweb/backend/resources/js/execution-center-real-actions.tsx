import React, { useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { useLocale } from './i18n';
import { AuthoritativeReconciliationError, mutateThenReconcile } from './reconciliation';

export interface ExecutionUrlState {
    page: number;
    pageSize: number;
    search: string;
    status: string;
    type: string;
    dateFrom: string;
    dateTo: string;
}

export const EXECUTION_PAGE_SIZES = [10, 25, 50, 100] as const;
export const EXECUTION_STATUSES = [
    '', 'queued', 'retrying', 'running', 'paused', 'failed', 'cancelled', 'completed',
] as const;

const DEFAULTS: ExecutionUrlState = {
    page: 1, pageSize: 25, search: '', status: '', type: '', dateFrom: '', dateTo: '',
};

export function readExecutionUrlState(): ExecutionUrlState {
    const params = new URLSearchParams(window.location.search);
    const page = Number(params.get('page'));
    const size = Number(params.get('per_page'));
    const bounded = (key: string, max: number, pattern: RegExp) => {
        const value = params.get(key) ?? '';
        return value.length <= max && pattern.test(value) ? value : '';
    };
    const status = params.get('status') ?? '';

    return {
        page: Number.isSafeInteger(page) && page > 0 ? page : DEFAULTS.page,
        pageSize: EXECUTION_PAGE_SIZES.includes(size as typeof EXECUTION_PAGE_SIZES[number])
            ? size : DEFAULTS.pageSize,
        search: bounded('search', 120, /^[a-zA-Z0-9 .:_-]*$/),
        status: EXECUTION_STATUSES.includes(status as typeof EXECUTION_STATUSES[number]) ? status : '',
        type: bounded('type', 80, /^[a-zA-Z0-9._-]*$/),
        dateFrom: bounded('date_from', 10, /^\d{4}-\d{2}-\d{2}$/),
        dateTo: bounded('date_to', 10, /^\d{4}-\d{2}-\d{2}$/),
    };
}

export function writeExecutionUrlState(next: ExecutionUrlState, replace = false): void {
    // Preserve unrelated tenant/site/tab parameters across navigation.
    const params = new URLSearchParams(window.location.search);
    const values: Record<string, string> = {
        page: String(next.page),
        per_page: String(next.pageSize),
        search: next.search,
        status: next.status,
        type: next.type,
        date_from: next.dateFrom,
        date_to: next.dateTo,
    };
    Object.entries(values).forEach(([key, value]) => {
        if (value) params.set(key, value);
        else params.delete(key);
    });
    const query = params.toString();
    const destination = window.location.pathname + (query ? '?' + query : '') + window.location.hash;
    if (destination !== window.location.pathname + window.location.search + window.location.hash) {
        if (replace) window.history.replaceState(window.history.state, '', destination);
        else window.history.pushState(window.history.state, '', destination);
    }
}

type ExecutionAction = 'cancel' | 'retry';

/**
 * These buttons never take a URL or authorization decision from a row. A row
 * supplies only the integer identity and server-calculated eligibility.
 * The API must recheck tenant/owner/state with a DB row lock on POST.
 */
export function authorizedExecutionAction(
    context: FrontendContext,
    row: Record<string, unknown>,
    action: ExecutionAction,
): string | null {
    const expected = '/tenants/' + encodeURIComponent(context.tenant.slug) + '/admin/operations';
    if (context.api.execution !== expected
        || !(context.permissions.includes('*')
            || (context.permissions.includes('operations.manage') && context.permissions.includes('execution.view')))
        || typeof row.id !== 'number' || !Number.isSafeInteger(row.id) || row.id <= 0) {
        return null;
    }
    const status = row.status;
    if (action === 'cancel' && !(row.can_cancel === true && (status === 'queued' || status === 'retrying'))) {
        return null;
    }
    if (action === 'retry' && !(row.can_retry === true && (status === 'failed' || status === 'cancelled'))) {
        return null;
    }

    return expected + '/' + row.id + '/' + action;
}

export function ExecutionCenterRowActions({
    context, row, onReconcile,
}: {
    context: FrontendContext;
    row: Record<string, unknown>;
    onReconcile: () => Promise<void>;
}) {
    const { locale } = useLocale();
    const cancelUrl = authorizedExecutionAction(context, row, 'cancel');
    const retryUrl = authorizedExecutionAction(context, row, 'retry');
    const [busy, setBusy] = useState<ExecutionAction | null>(null);
    const [message, setMessage] = useState('');
    const [error, setError] = useState(false);

    const run = async (action: ExecutionAction): Promise<void> => {
        const url = authorizedExecutionAction(context, row, action);
        if (busy || !url) return;
        if (action === 'cancel' && !window.confirm(
            locale === 'ar'
                ? 'هل تريد إلغاء العملية ' + row.id + '؟ لا يمكن إلغاء عمل بدأ تعديلًا غير آمن.'
                : 'Cancel execution ' + row.id + '? Unsafe mutations cannot be cancelled.',
        )) return;
        setBusy(action);
        setError(false);
        setMessage('');
        try {
            await mutateThenReconcile(
                () => apiRequest(url, { method: 'POST' }),
                onReconcile,
            );
            setMessage(locale === 'ar' ? 'تم تأكيد الحالة من الخادم.' : 'Server state verified.');
        } catch (cause) {
            setError(true);
            setMessage(cause instanceof AuthoritativeReconciliationError
                ? (locale === 'ar'
                    ? 'قُبل الطلب لكن إعادة قراءة الحالة فشلت. حدّث الصفحة قبل تكراره.'
                    : 'The request was accepted but refresh failed. Reload before repeating it.')
                : (locale === 'ar'
                    ? 'رفض الخادم الإجراء أو تعذر تنفيذه. حدّث السجل وراجع حالته.'
                    : 'The server rejected or could not perform this action. Reload its current state.'));
        } finally {
            setBusy(null);
        }
    };

    if (!cancelUrl && !retryUrl) {
        return <small className="muted" aria-label={locale === 'ar' ? 'لا توجد إجراءات مؤهلة' : 'No eligible actions'}>
            {locale === 'ar' ? 'لا يوجد إجراء آمن لهذه الحالة' : 'No safe action for this state'}
        </small>;
    }

    return (
        <div className="site-row-actions" aria-busy={Boolean(busy)}>
            {retryUrl ? (
                <button type="button" className="btn compact" disabled={Boolean(busy)}
                    onClick={() => { void run('retry'); }}
                    aria-label={locale === 'ar' ? 'إعادة محاولة العملية ' + row.id : 'Retry execution ' + row.id}>
                    {locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}
                </button>
            ) : null}
            {cancelUrl ? (
                <button type="button" className="btn compact" disabled={Boolean(busy)}
                    onClick={() => { void run('cancel'); }}
                    aria-label={locale === 'ar' ? 'إلغاء العملية ' + row.id : 'Cancel execution ' + row.id}>
                    {locale === 'ar' ? 'إلغاء' : 'Cancel'}
                </button>
            ) : null}
            {message ? <small role={error ? 'alert' : 'status'}>{message}</small> : null}
        </div>
    );
}
