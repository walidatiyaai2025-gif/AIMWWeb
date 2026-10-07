import React, { useMemo, useState } from 'react';
import { apiRequest, type FrontendContext } from './core';

export const COPY_REPORT_OPERATION_ID = 'AIMW-BILL-39BB044AF2';
export const RUN_VALIDATION_OPERATION_ID = 'AIMW-BILL-EE0BEAAC55';

export type ConfigurationValidationItem = {
    key: string;
    title: string;
    status: 'valid' | 'warning' | 'error';
    value: string;
    message: string;
};

export type ConfigurationValidationReport = {
    operation_id: string;
    checked_at_utc: string;
    critical_count: number;
    warning_count: number;
    items: ConfigurationValidationItem[];
};

const COPY_FAILURE_MESSAGE = 'The browser did not confirm a clipboard write. No copy success was reported; you can retry.';
const RUN_FAILURE_MESSAGE = 'Configuration validation could not complete. Internal server details were withheld; you can retry.';

export function buildConfigurationValidationRunRequest(endpoint: string): { url: string; init: RequestInit } {
    return { url: endpoint, init: { method: 'POST' } };
}

export function formatConfigurationValidationReport(report: ConfigurationValidationReport): string {
    const result = report.critical_count > 0
        ? 'Blocking errors found'
        : report.warning_count > 0
            ? 'No blockers; warnings require review'
            : 'No blockers detected in these checks';

    return [
        'AI WordPress Manager - Configuration Validation',
        `Checked: ${report.checked_at_utc}`,
        `Critical: ${report.critical_count}`,
        `Warnings: ${report.warning_count}`,
        `Result: ${result}`,
        '',
        ...report.items.flatMap((item) => [
            `[${item.status}] ${item.title}: ${item.value}`,
            item.message,
        ]),
    ].join('\n');
}

export function ConfigurationValidationCopyReportControl({ context }: { context: FrontendContext }) {
    const endpoint = context.api['configuration-validation'];
    const runEndpoint = context.api['configuration-validation-run'];
    const [copying, setCopying] = useState(false);
    const [running, setRunning] = useState(false);
    const [copySuccess, setCopySuccess] = useState(false);
    const [copyError, setCopyError] = useState('');
    const [runError, setRunError] = useState('');
    const [report, setReport] = useState<ConfigurationValidationReport | null>(null);

    const copyDisabled = !endpoint || copying;
    const runDisabled = !runEndpoint || running;

    const runValidation = async () => {
        if (runDisabled || !runEndpoint) return;

        setRunning(true);
        setRunError('');
        setCopySuccess(false);
        setCopyError('');

        try {
            const request = buildConfigurationValidationRunRequest(runEndpoint);
            const loaded = await apiRequest<ConfigurationValidationReport>(request.url, request.init);
            if (loaded.operation_id !== RUN_VALIDATION_OPERATION_ID) throw new Error('Unexpected validation operation');
            setReport(loaded);
        } catch {
            setReport(null);
            setRunError(RUN_FAILURE_MESSAGE);
        } finally {
            setRunning(false);
        }
    };

    const copy = async () => {
        if (copyDisabled || !endpoint) return;

        setCopying(true);
        setCopySuccess(false);
        setCopyError('');

        try {
            const loaded = report ?? await apiRequest<ConfigurationValidationReport>(endpoint);
            setReport(loaded);

            const clipboard = navigator.clipboard;
            if (!clipboard || typeof clipboard.writeText !== 'function') throw new Error('Clipboard API unavailable');

            await clipboard.writeText(formatConfigurationValidationReport(loaded));
            setCopySuccess(true);
        } catch {
            setCopyError(COPY_FAILURE_MESSAGE);
        } finally {
            setCopying(false);
        }
    };

    const copyLabel = useMemo(
        () => copying ? 'Copying…' : copyError ? 'Retry copy' : 'Copy report',
        [copying, copyError],
    );
    const runLabel = useMemo(
        () => running ? 'Running…' : runError ? 'Retry validation' : 'Run validation',
        [running, runError],
    );

    return (
        <section className="hero-panel" data-canonical-operation={COPY_REPORT_OPERATION_ID}>
            <div>
                <span className="workspace-kicker">CONFIGURATION</span>
                <h2>Configuration Validation</h2>
                <p>Run bounded runtime, storage and security checks, then copy only the sanitized result.</p>
            </div>
            <div>
                <button
                    type="button"
                    className="btn primary"
                    disabled={runDisabled}
                    aria-busy={running}
                    data-canonical-operation={RUN_VALIDATION_OPERATION_ID}
                    onClick={() => void runValidation()}
                >
                    {runLabel}
                </button>
                <button type="button" className="btn" disabled={copyDisabled} aria-busy={copying} onClick={() => void copy()}>
                    {copyLabel}
                </button>

                {runError ? <p role="alert">{runError}</p> : null}
                {copySuccess ? <p role="status">Validation report copied. The browser confirmed the clipboard write.</p> : null}
                {copyError ? <p role="alert">{copyError}</p> : null}

                {report ? (
                    <div data-validation-result>
                        <p role="status">
                            {report.critical_count > 0
                                ? 'Blocking errors found in the configuration checks.'
                                : report.warning_count > 0
                                    ? 'No blocking errors; warnings require review.'
                                    : 'No blocking errors detected in these configuration checks.'}
                        </p>
                        <p>Critical: {report.critical_count} · Warnings: {report.warning_count}</p>
                        <ul>
                            {report.items.map((item) => (
                                <li key={item.key}>
                                    <strong>{item.title}</strong>: {item.value} — {item.message}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </div>
        </section>
    );
}
