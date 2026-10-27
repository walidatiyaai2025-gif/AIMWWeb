import { describe, expect, it, vi } from 'vitest';
import {
    APP_DATA_GRID_CSV_EXPORT_OPERATION_ID,
    APP_DATA_GRID_CSV_EXPORT_SOURCE_OPERATION_KEY,
    requestAppDataGridCsvExport,
} from '../app-data-grid-csv-export-control';

describe('AIMW-SYNC-EB9202408A AppDataGrid ExportCsvAsync', () => {
    it('maps authoritative rows through the row selector and invokes the real downloader', async () => {
        const source = [{ id: 2, name: 'beta' }, { id: 1, name: 'alpha' }];
        const download = vi.fn();

        await expect(requestAppDataGridCsvExport(
            source,
            (row) => [String(row.id), row.name],
            download,
        )).resolves.toBe(true);

        expect(download).toHaveBeenCalledTimes(1);
        expect(download).toHaveBeenCalledWith([
            ['2', 'beta'],
            ['1', 'alpha'],
        ]);
        expect(source).toEqual([{ id: 2, name: 'beta' }, { id: 1, name: 'alpha' }]);
    });

    it('does not dispatch a download for an empty filtered collection', async () => {
        const download = vi.fn();
        await expect(requestAppDataGridCsvExport([], () => [], download)).resolves.toBe(false);
        expect(download).not.toHaveBeenCalled();
    });

    it('preserves exact canonical identity', () => {
        expect(APP_DATA_GRID_CSV_EXPORT_OPERATION_ID).toBe('AIMW-SYNC-EB9202408A');
        expect(APP_DATA_GRID_CSV_EXPORT_SOURCE_OPERATION_KEY).toContain('@ExportText [ExportCsvAsync]');
    });
});
