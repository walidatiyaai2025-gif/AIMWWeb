import React from 'react';
import { useLocale } from './i18n';

export const DASHBOARD_CONNECTOR_DOWNLOAD_OPERATION_ID = 'AIMW-CONT-9C12A6D4B1';

/** First-party, build-pinned WordPress plugin delivered in the signed release. */
export function DashboardConnectorDownloadControl() {
    const { locale } = useLocale();
    return (
        <a
            className="btn"
            href="/downloads/aimw-connector.zip"
            download="aimw-connector.zip"
            data-canonical-operation={DASHBOARD_CONNECTOR_DOWNLOAD_OPERATION_ID}
            aria-label={locale === 'ar' ? 'تحميل إضافة AIMW Connector لووردبريس' : 'Download AIMW Connector for WordPress'}
        >
            <span aria-hidden="true">↓</span>
            {locale === 'ar' ? 'تحميل إضافة WordPress' : 'Download WordPress plugin'}
        </a>
    );
}
