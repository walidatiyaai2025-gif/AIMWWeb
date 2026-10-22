import { beforeEach, describe, expect, it, vi } from 'vitest';
import { formatErrorDetails, wireCopyErrorDetails, type ErrorDetailsPayload } from '../error-copy-details';

const payload: ErrorDetailsPayload = {
    errorId: 'error-request-0001',
    correlationId: 'error-correlation-0001',
};

function renderControl(): void {
    document.body.innerHTML = `
        <button type="button" data-copy-error-details data-canonical-operation="AIMW-SYNC-89777052CB" aria-busy="false">Copy error details</button>
        <p data-copy-error-success role="status" hidden>copied</p>
        <div data-copy-error-error role="alert" hidden>
            <p data-copy-error-error-message></p>
            <button type="button" data-copy-error-retry>Retry copy</button>
        </div>
        <script id="error-details-payload" type="application/json">${JSON.stringify(payload)}</script>
    `;
}

async function settle(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

describe('copy error details visible control', () => {
    beforeEach(() => {
        renderControl();
        Object.defineProperty(navigator, 'clipboard', { value: undefined, configurable: true });
    });

    it('formats only the safe source tracking contract', () => {
        expect(formatErrorDetails(payload, '2026-08-30T20:45:00.000Z')).toBe([
            'AI WordPress Manager Error',
            'Error ID: error-request-0001',
            'Correlation ID: error-correlation-0001',
            'Time: 2026-08-30T20:45:00.000Z',
        ].join('\n'));
    });

    it('reports success only after the clipboard write resolves and suppresses duplicate clicks', async () => {
        let resolveWrite!: () => void;
        const pending = new Promise<void>((resolve) => { resolveWrite = resolve; });
        const writeText = vi.fn(() => pending);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        wireCopyErrorDetails();

        const button = document.querySelector<HTMLButtonElement>('[data-copy-error-details]')!;
        const success = document.querySelector<HTMLElement>('[data-copy-error-success]')!;

        button.click();
        button.click();

        expect(writeText).toHaveBeenCalledTimes(1);
        expect(writeText).toHaveBeenCalledWith(expect.stringContaining('AI WordPress Manager Error'));
        expect(writeText).toHaveBeenCalledWith(expect.stringContaining('Error ID: error-request-0001'));
        expect(writeText).toHaveBeenCalledWith(expect.stringContaining('Correlation ID: error-correlation-0001'));
        expect(writeText).toHaveBeenCalledWith(expect.stringMatching(/Time: \d{4}-\d{2}-\d{2}T/));
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

    it('never emits fake success on failure and allows a successful retry', async () => {
        const writeText = vi.fn()
            .mockRejectedValueOnce(new Error('denied'))
            .mockResolvedValueOnce(undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        wireCopyErrorDetails();

        const button = document.querySelector<HTMLButtonElement>('[data-copy-error-details]')!;
        const retry = document.querySelector<HTMLButtonElement>('[data-copy-error-retry]')!;
        const success = document.querySelector<HTMLElement>('[data-copy-error-success]')!;
        const error = document.querySelector<HTMLElement>('[data-copy-error-error]')!;
        const errorMessage = document.querySelector<HTMLElement>('[data-copy-error-error-message]')!;

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

    it('fails truthfully when Clipboard API or payload is unavailable', async () => {
        wireCopyErrorDetails();
        document.querySelector<HTMLButtonElement>('[data-copy-error-details]')!.click();
        await settle();

        expect(document.querySelector<HTMLElement>('[data-copy-error-success]')!.hidden).toBe(true);
        expect(document.querySelector<HTMLElement>('[data-copy-error-error]')!.hidden).toBe(false);

        renderControl();
        document.querySelector('#error-details-payload')!.textContent = '{invalid';
        wireCopyErrorDetails();

        expect(document.querySelector<HTMLButtonElement>('[data-copy-error-details]')!.disabled).toBe(true);
        expect(document.querySelector<HTMLElement>('[data-copy-error-error]')!.hidden).toBe(false);
    });
});
