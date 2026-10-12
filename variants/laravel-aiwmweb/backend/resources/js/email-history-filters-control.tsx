import React from 'react';
import type { Locale } from './core';

export const EMAIL_HISTORY_APPLY_FILTERS_OPERATION_ID = 'AIMW-EMAI-F8E8A2BEE9';

export type EmailHistoryFilterState = {
    siteId: string;
    status: string;
    correlation: string;
};

export function EmailHistoryFiltersControl({
    value,
    locale,
    busy,
    onChange,
    onApply,
}: {
    value: EmailHistoryFilterState;
    locale: Locale;
    busy: boolean;
    onChange: (next: EmailHistoryFilterState) => void;
    onApply: () => void;
}) {
    return (
        <form
            className="search-form email-history-filters"
            data-canonical-operation={EMAIL_HISTORY_APPLY_FILTERS_OPERATION_ID}
            onSubmit={(event) => {
                event.preventDefault();
                onApply();
            }}
        >
            <label>
                <span className="sr-only">{locale === 'ar' ? 'معرّف الموقع' : 'Site ID'}</span>
                <input
                    type="number"
                    min="1"
                    inputMode="numeric"
                    value={value.siteId}
                    onChange={(event) => onChange({ ...value, siteId: event.target.value })}
                    placeholder={locale === 'ar' ? 'معرّف الموقع' : 'Site ID'}
                    disabled={busy}
                />
            </label>
            <label>
                <span className="sr-only">{locale === 'ar' ? 'الحالة' : 'Status'}</span>
                <select
                    aria-label={locale === 'ar' ? 'الحالة' : 'Status'}
                    value={value.status}
                    onChange={(event) => onChange({ ...value, status: event.target.value })}
                    disabled={busy}
                >
                    <option value="">{locale === 'ar' ? 'كل الحالات' : 'All statuses'}</option>
                    {['QUEUED', 'SENDING', 'RETRYING', 'SENT', 'FAILED', 'SUPPRESSED'].map((status) => (
                        <option key={status} value={status}>{status}</option>
                    ))}
                </select>
            </label>
            <label>
                <span className="sr-only">Correlation ID</span>
                <input
                    value={value.correlation}
                    maxLength={100}
                    onChange={(event) => onChange({ ...value, correlation: event.target.value })}
                    placeholder="Correlation ID"
                    disabled={busy}
                />
            </label>
            <button
                type="submit"
                className="btn primary"
                data-canonical-operation={EMAIL_HISTORY_APPLY_FILTERS_OPERATION_ID}
                disabled={busy}
                aria-busy={busy ? 'true' : 'false'}
            >
                {busy
                    ? (locale === 'ar' ? 'جارٍ التطبيق…' : 'Applying…')
                    : (locale === 'ar' ? 'تطبيق' : 'Apply')}
            </button>
        </form>
    );
}
