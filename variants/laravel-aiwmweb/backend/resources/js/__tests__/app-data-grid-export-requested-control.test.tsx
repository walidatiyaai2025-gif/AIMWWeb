import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_EXPORT_REQUESTED_OPERATION_ID,
    APP_DATA_GRID_EXPORT_REQUESTED_SOURCE_OPERATION_KEY,
    requestAppDataGridExport,
} from '../app-data-grid-export-requested-control';
import { SiteOperationsExportControl } from '../site-operations-export-control';

describe(`${APP_DATA_GRID_EXPORT_REQUESTED_OPERATION_ID} AppDataGrid ExportAsync`, () => {
    it('forwards a cloned authoritative row collection to the consumer callback', async () => {
        const rows = [
            { id: 1, operation: 'alpha.sync' },
            { id: 2, operation: 'beta.sync' },
        ];
        const onExportRequested = vi.fn();

        const exported = await requestAppDataGridExport(rows, onExportRequested);

        expect(exported).toBe(true);
        expect(onExportRequested).toHaveBeenCalledTimes(1);
        const forwarded = onExportRequested.mock.calls[0][0];
        expect(forwarded).toEqual(rows);
        expect(forwarded).not.toBe(rows);
        expect(rows).toEqual([
            { id: 1, operation: 'alpha.sync' },
            { id: 2, operation: 'beta.sync' },
        ]);
    });

    it('does not invoke the consumer callback when the filtered collection is empty', async () => {
        const onExportRequested = vi.fn();

        await expect(requestAppDataGridExport([], onExportRequested)).resolves.toBe(false);
        expect(onExportRequested).not.toHaveBeenCalled();
    });

    it('binds the canonical generic export operation to the real Site Operations export control', () => {
        expect(APP_DATA_GRID_EXPORT_REQUESTED_OPERATION_ID).toBe('AIMW-SYNC-C18ED1F753');
        expect(APP_DATA_GRID_EXPORT_REQUESTED_SOURCE_OPERATION_KEY)
            .toContain('@ExportText [ExportAsync]');

        render(
            <SiteOperationsExportControl
                rows={[{ id: 1, operation: 'alpha.sync', status: 'succeeded' }]}
                locale="en"
            />,
        );

        const button = screen.getByRole('button', { name: 'Export CSV' });
        expect(button).toHaveAttribute(
            'data-canonical-export-requested-operation',
            APP_DATA_GRID_EXPORT_REQUESTED_OPERATION_ID,
        );
        expect(button).toBeEnabled();
    });

    it('preserves the source disabled-empty behavior on the integrated control', () => {
        render(<SiteOperationsExportControl rows={[]} locale="en" />);

        const button = screen.getByRole('button', { name: 'Export CSV' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute(
            'data-canonical-export-requested-operation',
            APP_DATA_GRID_EXPORT_REQUESTED_OPERATION_ID,
        );
    });
});
