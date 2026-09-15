import React from 'react';

export const RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID = 'AIMW-PLAT-4BAE8344AF';

export function RuntimeErrorHardReloadControl() {
    return (
        <a
            className="btn"
            href={window.location.href}
            data-canonical-operation={RUNTIME_ERROR_HARD_RELOAD_OPERATION_ID}
        >Hard reload</a>
    );
}
