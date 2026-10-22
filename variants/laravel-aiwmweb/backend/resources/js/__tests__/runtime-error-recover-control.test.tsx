import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    RUNTIME_ERROR_RECOVER_OPERATION_ID,
    RuntimeErrorBoundary,
} from '../runtime-error-boundary';

function ThrowUntilRecovered({ state }: { state: { shouldThrow: boolean } }) {
    if (state.shouldThrow) {
        throw new Error('recoverable runtime failure');
    }

    return <div>Recovered application tree</div>;
}

function AlwaysThrows(): React.JSX.Element {
    throw new Error('persistent runtime failure');
}

describe('AIMW-PLAT-C6260410D1 runtime error Recover control', () => {
    let consoleError: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        window.history.replaceState({}, '', '/tenants/alpha/module/posts');
    });

    afterEach(() => {
        consoleError.mockRestore();
        vi.restoreAllMocks();
        window.history.replaceState({}, '', '/');
    });

    it('binds the exact canonical operation to the source-equivalent recover button', () => {
        render(
            <RuntimeErrorBoundary>
                <AlwaysThrows />
            </RuntimeErrorBoundary>,
        );

        const recover = screen.getByRole('button', { name: 'Try to recover' });
        expect(RUNTIME_ERROR_RECOVER_OPERATION_ID).toBe('AIMW-PLAT-C6260410D1');
        expect(recover).toHaveAttribute('data-canonical-operation', RUNTIME_ERROR_RECOVER_OPERATION_ID);
        expect(document.querySelectorAll(`[data-canonical-operation="${RUNTIME_ERROR_RECOVER_OPERATION_ID}"]`)).toHaveLength(1);
    });

    it('recovers locally by clearing captured error state without navigation or network activity', async () => {
        const state = { shouldThrow: true };
        const fetchSpy = vi.spyOn(globalThis, 'fetch');

        render(
            <RuntimeErrorBoundary>
                <ThrowUntilRecovered state={state} />
            </RuntimeErrorBoundary>,
        );

        expect(await screen.findByRole('alert')).toHaveTextContent('recoverable runtime failure');
        state.shouldThrow = false;
        fireEvent.click(screen.getByRole('button', { name: 'Try to recover' }));

        await waitFor(() => expect(screen.getByText('Recovered application tree')).toBeInTheDocument());
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(window.location.pathname).toBe('/tenants/alpha/module/posts');
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('does not fabricate recovery when the child still fails', async () => {
        render(
            <RuntimeErrorBoundary>
                <AlwaysThrows />
            </RuntimeErrorBoundary>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Try to recover' }));

        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('persistent runtime failure'));
        expect(screen.getByRole('button', { name: 'Try to recover' })).toBeInTheDocument();
        expect(window.location.pathname).toBe('/tenants/alpha/module/posts');
    });
});
