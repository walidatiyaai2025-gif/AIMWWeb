import { beforeEach, describe, expect, it, vi } from 'vitest';
import { formatBuildReport, wireCopyBuildReport, type BuildReportPayload } from '../about-build-copy-report';

const payload: BuildReportPayload = {
    assemblyName: 'Laravel AIWMWeb',
    version: '1.2.3',
    informationalVersion: '1.2.3+abc123',
    branch: 'release/test',
    commit: 'abc123def456',
    buildTimeUtc: '2026-08-28T22:00:00Z',
    currentRelease: null,
};

function renderControl(): void {
    document.body.innerHTML = `
        <button type="button" data-copy-build-report aria-busy="false">Copy build report</button>
        <p data-copy-build-success role="status" hidden>copied</p>
        <div data-copy-build-error role="alert" hidden>
            <p data-copy-build-error-message></p>
            <button type="button" data-copy-build-retry>Retry copy</button>
        </div>
        <script id="build-report-payload" type="application/json">${JSON.stringify(payload)}</script>
    `;
}

async function settle(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

describe('copy build report visible control', () => {
    beforeEach(() => {
        renderControl();
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    });

    it('formats the authoritative build snapshot using the source report contract', () => {
        expect(formatBuildReport(payload)).toBe([
            'AI WordPress Manager - Build Report',
            'Version: 1.2.3',
            'Informational version: 1.2.3+abc123',
            'Branch: release/test',
            'Commit: abc123def456',
            'Build time UTC: 2026-08-28T22:00:00Z',
            'Assembly: Laravel AIWMWeb',
        ].join('\n'));
    });

    it('reports success only after the browser confirms the clipboard write and blocks duplicate clicks while busy', async () => {
        let resolveWrite!: () => void;
        const pending = new Promise<void>((resolve) => { resolveWrite = resolve; });
        const writeText = vi.fn(() => pending);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        wireCopyBuildReport();

        const button = document.querySelector<HTMLButtonElement>('[data-copy-build-report]')!;
        const success = document.querySelector<HTMLElement>('[data-copy-build-success]')!;

        button.click();
        button.click();

        expect(writeText).toHaveBeenCalledTimes(1);
        expect(writeText).toHaveBeenCalledWith(formatBuildReport(payload));
        expect(button.disabled).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');
        expect(success.hidden).toBe(true);

        resolveWrite();
        await pending;
        await settle();

        expect(success.hidden).toBe(false);
        expect(button.disabled).toBe(false);
        expect(button.getAttribute('aria-busy')).toBe('false');
    });

    it('never emits fake success on clipboard failure and a retry can succeed', async () => {
        const writeText = vi.fn()
            .mockRejectedValueOnce(new Error('denied'))
            .mockResolvedValueOnce(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        wireCopyBuildReport();

        const button = document.querySelector<HTMLButtonElement>('[data-copy-build-report]')!;
        const retry = document.querySelector<HTMLButtonElement>('[data-copy-build-retry]')!;
        const success = document.querySelector<HTMLElement>('[data-copy-build-success]')!;
        const error = document.querySelector<HTMLElement>('[data-copy-build-error]')!;
        const errorMessage = document.querySelector<HTMLElement>('[data-copy-build-error-message]')!;

        button.click();
        await settle();

        expect(success.hidden).toBe(true);
        expect(error.hidden).toBe(false);
        expect(errorMessage.textContent).toContain('browser did not confirm a clipboard write');

        retry.click();
        await settle();

        expect(writeText).toHaveBeenCalledTimes(2);
        expect(error.hidden).toBe(true);
        expect(success.hidden).toBe(false);
    });

    it('fails truthfully when the Clipboard API is unavailable', async () => {
        wireCopyBuildReport();

        document.querySelector<HTMLButtonElement>('[data-copy-build-report]')!.click();
        await settle();

        expect(document.querySelector<HTMLElement>('[data-copy-build-success]')!.hidden).toBe(true);
        expect(document.querySelector<HTMLElement>('[data-copy-build-error]')!.hidden).toBe(false);
    });
});
