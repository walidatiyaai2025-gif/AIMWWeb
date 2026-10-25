import React, { useRef } from 'react';
import type { FrontendContext } from './core';

export const SYNC_APP_FORM_ACTIONS_OPERATION_ID = 'AIMW-SYNC-7071DA172F';
export const SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID = 'AIMW-SYNC-14DAFE7D73';

function allowed(context: FrontendContext, permission: string): boolean {
    return context.permissions.includes('*') || context.permissions.includes(permission);
}

export function SyncFormActionsControl({
    context,
    busy,
    disabled = false,
    cancelDisabled = false,
    showCancel = true,
    dirty = false,
    hint,
    saveText = 'Save',
    savingText = 'Saving',
    cancelText = 'Cancel',
    saveOperationId,
    cancelOperationId,
    requiredPermission = 'sites.manage',
    onSave,
    onCancel,
}: {
    context: FrontendContext;
    busy: boolean;
    disabled?: boolean;
    cancelDisabled?: boolean;
    showCancel?: boolean;
    dirty?: boolean;
    hint?: string;
    saveText?: string;
    savingText?: string;
    cancelText?: string;
    saveOperationId?: string;
    cancelOperationId?: string;
    requiredPermission?: string;
    onSave: () => Promise<unknown> | unknown;
    onCancel?: () => Promise<unknown> | unknown;
}) {
    const dispatching = useRef(false);

    if (!allowed(context, requiredPermission)) return null;

    const save = async () => {
        if (busy || disabled || dispatching.current) return;
        dispatching.current = true;
        try {
            await onSave();
        } finally {
            dispatching.current = false;
        }
    };

    const cancel = async () => {
        if (busy || cancelDisabled) return;
        await onCancel?.();
    };

    return (
        <div
            className="app-form-actions toolbar-actions"
            aria-busy={busy}
            data-canonical-operation={SYNC_APP_FORM_ACTIONS_OPERATION_ID}
            data-source-handler="SaveClickedAsync"
        >
            <div className="app-form-actions__state">
                {dirty ? <span className="app-form-actions__dirty" role="status">Unsaved changes</span> : null}
                {hint ? <small>{hint}</small> : null}
            </div>
            <div className="app-form-actions__buttons">
                {showCancel ? (
                    <button
                        type="button"
                        className="btn"
                        disabled={busy || cancelDisabled}
                        data-canonical-operation={cancelOperationId ?? SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID}
                        data-source-handler="CancelClickedAsync"
                        onClick={() => void cancel()}
                    >
                        {cancelText}
                    </button>
                ) : null}
                <button
                    type="button"
                    className="btn primary"
                    disabled={busy || disabled}
                    onClick={() => void save()}
                    data-canonical-operation={saveOperationId ?? SYNC_APP_FORM_ACTIONS_OPERATION_ID}
                >
                    {busy ? savingText : saveText}
                </button>
            </div>
        </div>
    );
}
