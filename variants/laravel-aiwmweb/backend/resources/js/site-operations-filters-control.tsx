import React from 'react';

export const SITE_OPERATIONS_RESET_FILTERS_OPERATION_ID = 'AIMW-CONT-915652366F';

export type SiteOperationsFilterState = {
    siteId: string;
    status: 'all' | 'success' | 'failed';
    fromDate: string;
    toDate: string;
};

type Row = Record<string, unknown>;
type Locale = 'en' | 'ar';

function localDateInput(value: Date): string {
    return [
        value.getFullYear(),
        String(value.getMonth() + 1).padStart(2, '0'),
        String(value.getDate()).padStart(2, '0'),
    ].join('-');
}

export function siteOperationsDefaultFilters(now = new Date()): SiteOperationsFilterState {
    const from = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    from.setDate(from.getDate() - 30);
    const to = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    return {
        siteId: '',
        status: 'all',
        fromDate: localDateInput(from),
        toDate: localDateInput(to),
    };
}

export function siteOperationsDateRangeIsValid(filters: SiteOperationsFilterState): boolean {
    return Boolean(filters.fromDate && filters.toDate && filters.fromDate <= filters.toDate);
}

function siteName(row: Row): string {
    if (typeof row.site_name === 'string') return row.site_name;
    const site = row.site;
    if (site && typeof site === 'object' && 'name' in site && typeof (site as { name?: unknown }).name === 'string') {
        return String((site as { name: string }).name);
    }
    return String(row.site_id ?? '');
}

function rowLocalDate(row: Row): string {
    if (typeof row.started_at !== 'string' || !row.started_at) return '';
    const parsed = new Date(row.started_at);
    if (Number.isNaN(parsed.getTime())) return '';
    return localDateInput(parsed);
}

function searchableDetails(value: unknown): string {
    if (value == null) return '';
    if (typeof value === 'string') return value;
    try {
        return JSON.stringify(value);
    } catch {
        return '';
    }
}

export function filterSiteOperationsRows(
    rows: Row[],
    query: string,
    filters: SiteOperationsFilterState,
): Row[] {
    if (!siteOperationsDateRangeIsValid(filters)) return [];

    const search = query.trim().toLocaleLowerCase();
    return rows.filter((row) => {
        const date = rowLocalDate(row);
        if (!date || date < filters.fromDate || date > filters.toDate) return false;
        if (filters.siteId && String(row.site_id ?? '') !== filters.siteId) return false;

        const status = String(row.status ?? '').toLocaleLowerCase();
        if (filters.status === 'success' && status !== 'succeeded') return false;
        if (filters.status === 'failed' && status !== 'failed') return false;

        if (!search) return true;
        const haystack = [
            siteName(row),
            row.operation,
            row.message,
            row.execution_job_id,
            searchableDetails(row.details),
        ].map((value) => String(value ?? '')).join(' ').toLocaleLowerCase();

        return haystack.includes(search);
    });
}

function availableSites(rows: Row[]): Array<{ id: string; name: string }> {
    const sites = new Map<string, string>();
    for (const row of rows) {
        const id = String(row.site_id ?? '');
        if (!id || sites.has(id)) continue;
        sites.set(id, siteName(row));
    }
    return Array.from(sites, ([id, name]) => ({ id, name }))
        .sort((left, right) => left.name.localeCompare(right.name));
}

export function SiteOperationsFiltersControl({
    rows,
    locale,
    value,
    onChange,
    onReset,
}: {
    rows: Row[];
    locale: Locale;
    value: SiteOperationsFilterState;
    onChange: (next: SiteOperationsFilterState) => void;
    onReset: () => void;
}) {
    const sites = availableSites(rows);
    const validDateRange = siteOperationsDateRangeIsValid(value);

    return (
        <section className="panel site-operations-filters" aria-label={locale === 'ar' ? 'عوامل تصفية عمليات المواقع' : 'Site operations filters'}>
            <div className="filter-grid">
                <label>
                    <span>{locale === 'ar' ? 'الموقع' : 'Site'}</span>
                    <select
                        value={value.siteId}
                        onChange={(event) => onChange({ ...value, siteId: event.target.value })}
                    >
                        <option value="">{locale === 'ar' ? 'كل المواقع' : 'All sites'}</option>
                        {sites.map((site) => <option key={site.id} value={site.id}>{site.name}</option>)}
                    </select>
                </label>
                <label>
                    <span>{locale === 'ar' ? 'النتيجة' : 'Result'}</span>
                    <select
                        value={value.status}
                        onChange={(event) => onChange({ ...value, status: event.target.value as SiteOperationsFilterState['status'] })}
                    >
                        <option value="all">{locale === 'ar' ? 'كل النتائج' : 'All results'}</option>
                        <option value="success">{locale === 'ar' ? 'ناجحة' : 'Successful'}</option>
                        <option value="failed">{locale === 'ar' ? 'فاشلة' : 'Failed'}</option>
                    </select>
                </label>
                <label>
                    <span>{locale === 'ar' ? 'من تاريخ' : 'From date'}</span>
                    <input
                        type="date"
                        aria-label={locale === 'ar' ? 'من تاريخ' : 'From date'}
                        value={value.fromDate}
                        onChange={(event) => onChange({ ...value, fromDate: event.target.value })}
                    />
                </label>
                <label>
                    <span>{locale === 'ar' ? 'إلى تاريخ' : 'To date'}</span>
                    <input
                        type="date"
                        aria-label={locale === 'ar' ? 'إلى تاريخ' : 'To date'}
                        value={value.toDate}
                        onChange={(event) => onChange({ ...value, toDate: event.target.value })}
                    />
                </label>
            </div>
            <button
                type="button"
                className="btn"
                data-canonical-operation={SITE_OPERATIONS_RESET_FILTERS_OPERATION_ID}
                onClick={onReset}
            >
                {locale === 'ar' ? 'إعادة الضبط' : 'Reset filters'}
            </button>
            {!validDateRange ? (
                <div className="alert error" role="alert">
                    {locale === 'ar'
                        ? 'تاريخ البداية يجب ألا يكون بعد تاريخ النهاية.'
                        : 'The start date cannot be after the end date.'}
                </div>
            ) : null}
        </section>
    );
}
