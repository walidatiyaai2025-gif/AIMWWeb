import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    SITE_OPERATION_COPY_REPORT_OPERATION_ID,
    formatSiteOperationReport,
    wireSiteOperationReportCopy,
    type SiteOperationReportPayload,
} from '../site-operation-details-copy-report';

const payload: SiteOperationReportPayload = {
    operationId: '11111111-1111-4111-8111-111111111111',
    site: 'Alpha Site',
    siteId: '17',
    executionJobId: '41',
    operation: 'content.sync',
    result: 'Success',
    startedUtc: '2026-10-12T07:00:00.000000Z',
    completedUtc: '2026-10-12T07:00:02.000000Z',
    durationMs: 2000,
    affectedRecords: 7,
    message: 'Synced authoritative content',
    details: '{\n    "trace_id": "trace-42",\n    "api_token": "[REDACTED]"\n}',
};

function mount(): void {
    document.body.innerHTML = `
        <button type="button" data-copy-operation-report data-canonical-operation="${SITE_OPERATION_COPY_REPORT_OPERATION_ID}" aria-busy="false">Copy report</button>
        <p data-copy-operation-report-success role="status" hidden>Report copied to the clipboard.</p>
        <div data-copy-operation-report-error role="alert" hidden>
            <p data-copy-operation-report-error-message></p>
            <button type="button" data-copy-operation-report-retry>Retry copy</button>
        </div>
        <script id="site-operation-report-payload" type="application/json">${JSON.stringify(payload)}</script>
    `;
}

describe('AIMW-BILL-AA8C2FD5B3 operation diagnostic report copy', () => {
    beforeEach(() => {
        mount();
    });

    it('formats the authoritative operation diagnostic report exactly like the source contract', () => {
        expect(formatSiteOperationReport(payload)).toBe([
            'AI WordPress Manager - Operation Diagnostic Report',
            'Operation ID: 11111111-1111-4111-8111-111111111111',
            'Site: Alpha Site',
            'Site ID: 17',
            'Execution Job ID: 41',
            'Operation: content.sync',
            'Result: Success',
            'Started UTC: 2026-10-12T07:00:00.000000Z',
            'Completed UTC: 2026-10-12T07:00:02.000000Z',
            'Duration ms: 2000',
            'Affected records: 7',
            'Message: Synced authoritative content',
            'Details:',
            '{\n    "trace_id": "trace-42",\n    "api_token": "[REDACTED]"\n}',
        ].join('\n'));
    });

    it('reports success only after the browser confirms clipboard write and suppresses duplicate concurrent copies', async () => {
        let resolveCopy!: () => void;
        const writeText = vi.fn(() => new Promise<void>((resolve) => {
            resolveCopy = resolve;
        }));
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        wireSiteOperationReportCopy();

        const button = document.querySelector<HTMLButtonElement>('[data-copy-operation-report]')!;
        const success = document.querySelector<HTMLElement>('[data-copy-operation-report-success]')!;
        button.click();
        button.click();

        expect(writeText).toHaveBeenCalledTimes(1);
        expect(writeText).toHaveBeenCalledWith(formatSiteOperationReport(payload));
        expect(success.hidden).toBe(true);
        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');

        resolveCopy();
        await vi.waitFor(() => expect(success.hidden).toBe(false));
        expect(button.disabled).toBe(false);
        expect(button.getAttribute('aria-busy')).toBe('false');
    });

    it('shows truthful failure when clipboard write rejects and allows retry', async () => {
        const writeText = vi.fn()
            .mockRejectedValueOnce(new Error('blocked'))
            .mockResolvedValueOnce(undefined);
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });

        wireSiteOperationReportCopy();

        document.querySelector<HTMLButtonElement>('[data-copy-operation-report]')!.click();

        const error = document.querySelector<HTMLElement>('[data-copy-operation-report-error]')!;
        const success = document.querySelector<HTMLElement>('[data-copy-operation-report-success]')!;
        await vi.waitFor(() => expect(error.hidden).toBe(false));
        expect(success.hidden).toBe(true);
        expect(document.querySelector('[data-copy-operation-report-error-message]')?.textContent)
            .toContain('No copy success was reported');

        document.querySelector<HTMLButtonElement>('[data-copy-operation-report-retry]')!.click();
        await vi.waitFor(() => expect(success.hidden).toBe(false));
        expect(error.hidden).toBe(true);
        expect(writeText).toHaveBeenCalledTimes(2);
    });

    it('fails closed when the Clipboard API is unavailable', async () => {
        Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
        wireSiteOperationReportCopy();

        document.querySelector<HTMLButtonElement>('[data-copy-operation-report]')!.click();

        await vi.waitFor(() =>
            expect(document.querySelector<HTMLElement>('[data-copy-operation-report-error]')!.hidden).toBe(false),
        );
        expect(document.querySelector<HTMLElement>('[data-copy-operation-report-success]')!.hidden).toBe(true);
    });
});
