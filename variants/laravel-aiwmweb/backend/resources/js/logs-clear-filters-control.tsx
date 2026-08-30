import React from 'react';
import { tenantUrl, type FrontendContext } from './core';
import { useLocale } from './i18n';

export const LOGS_CLEAR_FILTERS_OPERATION_ID = 'AIMW-CONT-83908F2D7C';

export function LogsClearFiltersControl({ context }: { context: FrontendContext }) {
    const { locale } = useLocale();
    const target = tenantUrl(context.tenant.slug, '/module/logs');

    return (
        <section className="toolbar-panel" aria-label={locale === 'ar' ? 'مرشحات السجلات' : 'Log filters'}>
            <a
                className="btn"
                href={target}
                data-canonical-operation={LOGS_CLEAR_FILTERS_OPERATION_ID}
            >
                {locale === 'ar' ? 'مسح الفلاتر' : 'Clear filters'}
            </a>
        </section>
    );
}
