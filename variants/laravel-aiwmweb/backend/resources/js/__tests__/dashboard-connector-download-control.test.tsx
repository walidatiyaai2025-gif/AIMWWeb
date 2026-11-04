import React from 'react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { DashboardConnectorDownloadControl, DASHBOARD_CONNECTOR_DOWNLOAD_OPERATION_ID } from '../dashboard-connector-download-control';
import { LocaleProvider } from '../i18n';

afterEach(() => {
    cleanup();
    window.localStorage.removeItem('aiwm.locale');
});

describe('Dashboard WordPress Connector download', () => {
    it('renders the same-origin installable plugin ZIP and a stable action marker', () => {
        render(<LocaleProvider><DashboardConnectorDownloadControl /></LocaleProvider>);
        const download = screen.getByRole('link', { name: 'Download AIMW Connector for WordPress' });
        expect(download).toHaveAttribute('href', '/downloads/aimw-connector.zip');
        expect(download).toHaveAttribute('download', 'aimw-connector.zip');
        expect(download).toHaveAttribute('data-canonical-operation', DASHBOARD_CONNECTOR_DOWNLOAD_OPERATION_ID);
        expect(download).not.toHaveAttribute('target');
    });

    it('is actually included in the authoritative dashboard actions', () => {
        const source = readFileSync(resolve(process.cwd(), 'resources/js/pages.tsx'), 'utf8');
        expect(source).toContain("import { DashboardConnectorDownloadControl } from './dashboard-connector-download-control';");
        expect(source).toContain('<DashboardConnectorDownloadControl />');
    });

    it('exposes an Arabic label without changing the verified download target', () => {
        window.localStorage.setItem('aiwm.locale', 'ar');
        render(<LocaleProvider><DashboardConnectorDownloadControl /></LocaleProvider>);
        expect(screen.getByRole('link', { name: 'تحميل إضافة AIMW Connector لووردبريس' }))
            .toHaveAttribute('href', '/downloads/aimw-connector.zip');
    });
});
