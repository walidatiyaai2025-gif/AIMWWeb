import React from 'react';

export const SITE_RELIABILITY_APPLY_FILTERS_OPERATION_ID = 'AIMW-CONT-40CD7F080F';

export type SiteReliabilityPeriodDays = '7' | '30' | '90';
export type SiteReliabilityMinimumOperations = '1' | '3' | '5' | '10';

export type SiteReliabilityFilterState = {
    periodDays: SiteReliabilityPeriodDays;
    minimumOperations: SiteReliabilityMinimumOperations;
};

export function siteReliabilityDefaultFilters(): SiteReliabilityFilterState {
    return { periodDays: '30', minimumOperations: '1' };
}

export function SiteReliabilityFiltersControl({
    locale,
    value,
    busy,
    onChange,
    onApply,
}: {
    locale: 'en' | 'ar';
    value: SiteReliabilityFilterState;
    busy: boolean;
    onChange: (value: SiteReliabilityFilterState) => void;
    onApply: (value: SiteReliabilityFilterState) => void;
}) {
    return (
        <section className="filter-panel site-reliability-filters" aria-label={locale === 'ar' ? 'مرشحات موثوقية المواقع' : 'Site reliability filters'}>
            <div className="filter-grid">
                <label>
                    <span>{locale === 'ar' ? 'الفترة' : 'Period'}</span>
                    <select
                        aria-label={locale === 'ar' ? 'الفترة' : 'Period'}
                        value={value.periodDays}
                        disabled={busy}
                        onChange={(event) => onChange({ ...value, periodDays: event.target.value as SiteReliabilityPeriodDays })}
                    >
                        <option value="7">{locale === 'ar' ? '7 أيام' : '7 days'}</option>
                        <option value="30">{locale === 'ar' ? '30 يومًا' : '30 days'}</option>
                        <option value="90">{locale === 'ar' ? '90 يومًا' : '90 days'}</option>
                    </select>
                </label>
                <label>
                    <span>{locale === 'ar' ? 'الحد الأدنى للعمليات' : 'Minimum operations'}</span>
                    <select
                        aria-label={locale === 'ar' ? 'الحد الأدنى للعمليات' : 'Minimum operations'}
                        value={value.minimumOperations}
                        disabled={busy}
                        onChange={(event) => onChange({ ...value, minimumOperations: event.target.value as SiteReliabilityMinimumOperations })}
                    >
                        <option value="1">1</option>
                        <option value="3">3</option>
                        <option value="5">5</option>
                        <option value="10">10</option>
                    </select>
                </label>
                <button
                    type="button"
                    className="btn primary"
                    data-canonical-operation={SITE_RELIABILITY_APPLY_FILTERS_OPERATION_ID}
                    disabled={busy}
                    aria-busy={busy ? 'true' : 'false'}
                    onClick={() => onApply(value)}
                >
                    {busy
                        ? (locale === 'ar' ? 'جارٍ التطبيق…' : 'Applying…')
                        : (locale === 'ar' ? 'تطبيق' : 'Apply')}
                </button>
            </div>
        </section>
    );
}
