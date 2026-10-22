export type ErrorDetailsPayload = {
    errorId: string;
    correlationId: string;
};

const FAILURE_MESSAGE = 'The browser did not confirm a clipboard write. No copy success was reported; you can retry.';

export function formatErrorDetails(payload: ErrorDetailsPayload, copiedAt: string = new Date().toISOString()): string {
    return [
        'AI WordPress Manager Error',
        `Error ID: ${payload.errorId}`,
        `Correlation ID: ${payload.correlationId}`,
        `Time: ${copiedAt}`,
    ].join('\n');
}

export function wireCopyErrorDetails(root: ParentNode = document): void {
    const button = root.querySelector<HTMLButtonElement>('[data-copy-error-details]');
    const retry = root.querySelector<HTMLButtonElement>('[data-copy-error-retry]');
    const success = root.querySelector<HTMLElement>('[data-copy-error-success]');
    const error = root.querySelector<HTMLElement>('[data-copy-error-error]');
    const errorMessage = root.querySelector<HTMLElement>('[data-copy-error-error-message]');
    const payloadNode = root.querySelector<HTMLScriptElement>('#error-details-payload');

    if (!button || !retry || !success || !error || !errorMessage || !payloadNode) return;

    let payload: ErrorDetailsPayload;
    try {
        payload = JSON.parse(payloadNode.textContent ?? '') as ErrorDetailsPayload;
        if (typeof payload.errorId !== 'string' || typeof payload.correlationId !== 'string') {
            throw new Error('Invalid error details payload');
        }
    } catch {
        errorMessage.textContent = 'The safe error details payload could not be read. No copy success was reported.';
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

            await clipboard.writeText(formatErrorDetails(payload));
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

wireCopyErrorDetails();
