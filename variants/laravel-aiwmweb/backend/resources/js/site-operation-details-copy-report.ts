export const SITE_OPERATION_COPY_REPORT_OPERATION_ID = 'AIMW-BILL-AA8C2FD5B3';

export type SiteOperationReportPayload = {
    operationId: string;
    site: string;
    siteId: string;
    executionJobId: string | null;
    operation: string;
    result: 'Success' | 'Failed' | string;
    startedUtc: string;
    completedUtc: string;
    durationMs: number;
    affectedRecords: number | null;
    message: string;
    details: string;
};

export function formatSiteOperationReport(payload: SiteOperationReportPayload): string {
    return [
        'AI WordPress Manager - Operation Diagnostic Report',
        `Operation ID: ${payload.operationId}`,
        `Site: ${payload.site}`,
        `Site ID: ${payload.siteId}`,
        `Execution Job ID: ${payload.executionJobId ?? 'N/A'}`,
        `Operation: ${payload.operation}`,
        `Result: ${payload.result}`,
        `Started UTC: ${payload.startedUtc}`,
        `Completed UTC: ${payload.completedUtc}`,
        `Duration ms: ${Math.max(0, Number(payload.durationMs) || 0).toFixed(0)}`,
        `Affected records: ${payload.affectedRecords ?? 'N/A'}`,
        `Message: ${payload.message}`,
        'Details:',
        payload.details || 'N/A',
    ].join('\n');
}

const FAILURE_MESSAGE = 'The report could not be written to the clipboard. No copy success was reported; check browser clipboard access and retry.';

export function wireSiteOperationReportCopy(root: ParentNode = document): void {
    const button = root.querySelector<HTMLButtonElement>('[data-copy-operation-report]');
    const retry = root.querySelector<HTMLButtonElement>('[data-copy-operation-report-retry]');
    const success = root.querySelector<HTMLElement>('[data-copy-operation-report-success]');
    const error = root.querySelector<HTMLElement>('[data-copy-operation-report-error]');
    const errorMessage = root.querySelector<HTMLElement>('[data-copy-operation-report-error-message]');
    const payloadNode = root.querySelector<HTMLScriptElement>('#site-operation-report-payload');

    if (!button || !retry || !success || !error || !errorMessage || !payloadNode) return;

    let payload: SiteOperationReportPayload;
    try {
        payload = JSON.parse(payloadNode.textContent ?? '') as SiteOperationReportPayload;
    } catch {
        errorMessage.textContent = 'The operation report payload could not be read. No copy success was reported.';
        error.hidden = false;
        button.disabled = true;
        retry.disabled = true;
        return;
    }

    let copying = false;

    const copy = async (): Promise<void> => {
        if (copying) return;

        copying = true;
        button.disabled = true;
        retry.disabled = true;
        button.setAttribute('aria-busy', 'true');
        success.hidden = true;
        error.hidden = true;

        try {
            const clipboard = navigator.clipboard;
            if (!clipboard || typeof clipboard.writeText !== 'function') {
                throw new Error('Clipboard API unavailable');
            }

            await clipboard.writeText(formatSiteOperationReport(payload));
            success.hidden = false;
        } catch {
            errorMessage.textContent = FAILURE_MESSAGE;
            error.hidden = false;
        } finally {
            copying = false;
            button.disabled = false;
            retry.disabled = false;
            button.setAttribute('aria-busy', 'false');
        }
    };

    button.addEventListener('click', () => void copy());
    retry.addEventListener('click', () => void copy());
}

wireSiteOperationReportCopy();
