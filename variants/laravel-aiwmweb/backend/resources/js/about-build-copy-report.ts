export type BuildReportRelease = {
    title: string;
    changes: string[];
};

export type BuildReportPayload = {
    assemblyName: string;
    version: string;
    informationalVersion: string;
    branch: string;
    commit: string;
    buildTimeUtc: string;
    currentRelease: BuildReportRelease | null;
};

const FAILURE_MESSAGE = 'The browser did not confirm a clipboard write. No copy success was reported; you can retry.';

export function formatBuildReport(payload: BuildReportPayload): string {
    const lines = [
        'AI WordPress Manager - Build Report',
        `Version: ${payload.version}`,
        `Informational version: ${payload.informationalVersion}`,
        `Branch: ${payload.branch}`,
        `Commit: ${payload.commit}`,
        `Build time UTC: ${payload.buildTimeUtc}`,
        `Assembly: ${payload.assemblyName}`,
    ];

    if (payload.currentRelease) {
        lines.push('', payload.currentRelease.title);
        lines.push(...payload.currentRelease.changes.map((change) => `- ${change}`));
    }

    return lines.join('\n');
}

export function wireCopyBuildReport(root: ParentNode = document): void {
    const button = root.querySelector<HTMLButtonElement>('[data-copy-build-report]');
    const retry = root.querySelector<HTMLButtonElement>('[data-copy-build-retry]');
    const success = root.querySelector<HTMLElement>('[data-copy-build-success]');
    const error = root.querySelector<HTMLElement>('[data-copy-build-error]');
    const errorMessage = root.querySelector<HTMLElement>('[data-copy-build-error-message]');
    const payloadNode = root.querySelector<HTMLScriptElement>('#build-report-payload');

    if (!button || !retry || !success || !error || !errorMessage || !payloadNode) return;

    let payload: BuildReportPayload;
    try {
        payload = JSON.parse(payloadNode.textContent ?? '') as BuildReportPayload;
    } catch {
        errorMessage.textContent = 'The build report payload could not be read. No copy success was reported.';
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

            await clipboard.writeText(formatBuildReport(payload));
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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => wireCopyBuildReport(), { once: true });
} else {
    wireCopyBuildReport();
}
