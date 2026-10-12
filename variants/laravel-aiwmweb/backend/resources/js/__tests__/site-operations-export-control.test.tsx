import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    buildSiteOperationsCsv,
    SITE_OPERATIONS_EXPORT_OPERATION_ID,
    SiteOperationsExportControl,
} from '../site-operations-export-control';

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('AIMW-BILL-582319B640 Site Operations ExportCsvAsync', () => {
    it('builds the authoritative operation rows in canonical CSV order with safe escaping', () => {
        const csv = buildSiteOperationsCsv([{
            id: 41,
            site_id: 7,
            site: { id: 7, name: 'Alpha, "Main"' },
            correlation_id: '1b23c4d5-6789-4abc-8def-1234567890ab',
            operation: 'sync.posts',
            status: 'failed',
            message: 'Failed, retry\nrequired',
            details: { code: 'timeout' },
            affected_records: 12,
            started_at: '2026-10-12T01:00:00Z',
            completed_at: '2026-10-12T01:00:01.250Z',
        }]);

        expect(csv).toContain('Time,Site,SiteId,ExecutionJobId,Operation,Result,DurationMs,Records,Message,Details');
        expect(csv).toContain('"Alpha, ""Main"""');
        expect(csv).toContain('sync.posts,Failed,1250,12');
        expect(csv).toContain('"Failed, retry\nrequired"');
        expect(csv).toContain('"{""code"":""timeout""}"');
    });

    it('fails closed when there are no authoritative rows to export', () => {
        render(<SiteOperationsExportControl rows={[]} locale="en" />);
        const button = screen.getByRole('button', { name: 'Export CSV' });

        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('data-canonical-operation', SITE_OPERATIONS_EXPORT_OPERATION_ID);
        expect(button).toHaveAttribute('title', 'There are no displayed operations to export.');
    });

    it('starts a real browser download only from the supplied authoritative rows', () => {
        const createObjectURL = vi.fn(() => 'blob:site-operations');
        const revokeObjectURL = vi.fn();
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: createObjectURL });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: revokeObjectURL });

        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
        render(<SiteOperationsExportControl rows={[{
            site_id: 7,
            site: { id: 7, name: 'Alpha' },
            operation: 'sync.posts',
            status: 'succeeded',
            message: 'ok',
            started_at: '2026-10-12T01:00:00Z',
            completed_at: '2026-10-12T01:00:00.500Z',
        }]} locale="en" />);

        const button = screen.getByRole('button', { name: 'Export CSV' });
        expect(button).toHaveAttribute('data-canonical-operation', SITE_OPERATIONS_EXPORT_OPERATION_ID);
        fireEvent.click(button);

        expect(createObjectURL).toHaveBeenCalledTimes(1);
        expect(click).toHaveBeenCalledTimes(1);
        expect(revokeObjectURL).toHaveBeenCalledWith('blob:site-operations');
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('reports a truthful error instead of success when browser download startup fails', () => {
        Object.defineProperty(URL, 'createObjectURL', {
            configurable: true,
            value: vi.fn(() => { throw new Error('downloads blocked'); }),
        });

        render(<SiteOperationsExportControl rows={[{
            site_id: 7,
            operation: 'sync.posts',
            status: 'succeeded',
            message: 'ok',
            started_at: '2026-10-12T01:00:00Z',
            completed_at: '2026-10-12T01:00:01Z',
        }]} locale="en" />);

        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
        expect(screen.getByRole('alert')).toHaveTextContent('could not be started');
    });
});