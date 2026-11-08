import React, { useEffect, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';
import { Pagination } from './components';
import { useLocale } from './i18n';

type PageMeta = {
    page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};
type AuditRow = { id: number; action: string; occurred_at: string };
type TransactionRow = {
    id: number;
    type: string;
    status: string;
    amount_minor: number | null;
    currency: string | null;
    occurred_at: string;
};
type BillingHistoryResponse = {
    data: { audit: AuditRow[]; transactions: TransactionRow[] };
    pagination: { audit: PageMeta; transactions: PageMeta };
};

function validPageMeta(meta: PageMeta | undefined): meta is PageMeta {
    return Boolean(meta
        && Number.isSafeInteger(meta.page) && meta.page >= 1
        && Number.isSafeInteger(meta.last_page) && meta.last_page >= 1
        && Number.isSafeInteger(meta.per_page) && [10, 25, 50, 100].includes(meta.per_page)
        && Number.isSafeInteger(meta.total) && meta.total >= 0
        && meta.page <= meta.last_page);
}

export function billingHistoryUrl(tenantSlug: string, auditPage: number, transactionPage: number, pageSize: number): string {
    const path = `/api/v1/tenants/${encodeURIComponent(tenantSlug)}/billing/history/paged`;
    const params = new URLSearchParams({
        audit_page: String(auditPage),
        transaction_page: String(transactionPage),
        per_page: String(pageSize),
    });
    return `${path}?${params.toString()}`;
}

export function BillingHistoryPanel({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const canRead = context.permissions.includes('*') || context.permissions.includes('billing.view');
    const [auditPage, setAuditPage] = useState(1);
    const [transactionPage, setTransactionPage] = useState(1);
    const [pageSize, setPageSize] = useState(25);
    const [refreshKey, setRefreshKey] = useState(0);
    const [result, setResult] = useState<BillingHistoryResponse | null>(null);
    const [loading, setLoading] = useState(canRead);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!canRead) return;
        let active = true;
        setLoading(true);
        setFailed(false);
        // Do not retain the previous tenant/page's rows during a new request.
        setResult(null);

        void apiRequest<BillingHistoryResponse>(billingHistoryUrl(context.tenant.slug, auditPage, transactionPage, pageSize))
            .then((response) => {
                if (!active) return;
                if (!Array.isArray(response?.data?.audit) || !Array.isArray(response?.data?.transactions)
                    || !validPageMeta(response?.pagination?.audit)
                    || !validPageMeta(response?.pagination?.transactions)) {
                    throw new Error('Invalid authoritative billing history response.');
                }
                setResult(response);
            })
            .catch(() => { if (active) setFailed(true); })
            .finally(() => { if (active) setLoading(false); });

        return () => { active = false; };
    }, [canRead, context.tenant.slug, auditPage, transactionPage, pageSize, refreshKey]);

    if (!canRead) return null;

    const dateLabel = (value: string): string => {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString(locale === 'ar' ? 'ar-KW' : 'en-US');
    };
    const onPageSize = (size: number) => {
        setPageSize(size);
        setAuditPage(1);
        setTransactionPage(1);
    };

    return (
        <section className="workspace-card" aria-label={locale === 'ar' ? 'سجل الفوترة' : 'Billing history'} data-testid="billing-history">
            <div className="toolbar">
                <div>
                    <h2>{locale === 'ar' ? 'سجل الفوترة' : 'Billing history'}</h2>
                    <p>{locale === 'ar' ? 'سجلات تدقيق ومعاملات فعلية، مع صفحات مستقلة.' : 'Actual billing audits and transactions with independent server pages.'}</p>
                </div>
                <button type="button" className="btn" disabled={loading}
                    onClick={() => setRefreshKey((value) => value + 1)}>
                    {locale === 'ar' ? 'تحديث' : 'Refresh'}
                </button>
            </div>
            {loading ? <p role="status">{locale === 'ar' ? 'جارٍ تحميل سجل الفوترة…' : 'Loading billing history…'}</p> : null}
            {failed ? (
                <div role="alert">
                    <p>{locale === 'ar' ? 'تعذر تحميل سجل الفوترة من الخادم.' : 'Could not load billing history from the server.'}</p>
                    <button type="button" className="btn" onClick={() => setRefreshKey((value) => value + 1)}>
                        {locale === 'ar' ? 'إعادة المحاولة' : 'Retry'}
                    </button>
                </div>
            ) : null}
            {result ? (
                <div className="workspace-stack">
                    <section data-testid="billing-history-audit">
                        <h3>{locale === 'ar' ? 'سجل التدقيق' : 'Audit events'}</h3>
                        {result.data.audit.length === 0 ? <p>{locale === 'ar' ? 'لا توجد أحداث تدقيق.' : 'No audit events.'}</p> : (
                            <div className="table-scroll"><table className="data-table">
                                <thead><tr><th>{locale === 'ar' ? 'الإجراء' : 'Action'}</th><th>{locale === 'ar' ? 'التاريخ' : 'Date'}</th></tr></thead>
                                <tbody>{result.data.audit.map((item) => <tr key={item.id}><td>{item.action}</td><td>{dateLabel(item.occurred_at)}</td></tr>)}</tbody>
                            </table></div>
                        )}
                        <Pagination page={result.pagination.audit.page} lastPage={result.pagination.audit.last_page}
                            total={result.pagination.audit.total} pageSize={result.pagination.audit.per_page}
                            returnedCount={result.data.audit.length}
                            onPage={setAuditPage} onPageSizeChange={onPageSize} />
                    </section>
                    <section data-testid="billing-history-transactions">
                        <h3>{locale === 'ar' ? 'المعاملات' : 'Transactions'}</h3>
                        {result.data.transactions.length === 0 ? <p>{locale === 'ar' ? 'لا توجد معاملات.' : 'No transactions.'}</p> : (
                            <div className="table-scroll"><table className="data-table">
                                <thead><tr><th>{locale === 'ar' ? 'النوع' : 'Type'}</th><th>{locale === 'ar' ? 'الحالة' : 'Status'}</th><th>{locale === 'ar' ? 'المبلغ' : 'Amount'}</th><th>{locale === 'ar' ? 'التاريخ' : 'Date'}</th></tr></thead>
                                <tbody>{result.data.transactions.map((item) => <tr key={item.id}>
                                    <td>{item.type}</td><td>{item.status}</td>
                                    <td>{Number.isSafeInteger(item.amount_minor) && item.amount_minor !== null
                                        ? `${(item.amount_minor / 100).toFixed(2)} ${item.currency ?? ''}`
                                        : '—'}</td>
                                    <td>{dateLabel(item.occurred_at)}</td>
                                </tr>)}</tbody>
                            </table></div>
                        )}
                        <Pagination page={result.pagination.transactions.page} lastPage={result.pagination.transactions.last_page}
                            total={result.pagination.transactions.total} pageSize={result.pagination.transactions.per_page}
                            returnedCount={result.data.transactions.length} onPage={setTransactionPage} />
                    </section>
                </div>
            ) : null}
        </section>
    );
}
