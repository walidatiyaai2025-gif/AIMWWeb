// Canonical PR CI anchor: AIMW-SYNC-7071DA172F remains bound to the source-equivalent AppFormActions wrapper.
// Exact-head CI retrigger after official issue 991 post-convergence parity materialization.
import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    SYNC_APP_FORM_ACTIONS_OPERATION_ID,
    SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID,
    SyncFormActionsControl,
} from '../sync-form-actions-control';

const context = (permissions: string[] = ['tenant.view', 'sites.manage']): FrontendContext => ({
    user: { id: 7, name: 'Operator', email: 'operator@example.test' },
    tenant: { slug: 'alpha', name: 'Alpha' },
    tenants: [{ slug: 'alpha', name: 'Alpha' }],
    permissions,
    connectors: [],
    capabilities: {},
    api: {},
    actions: {},
});

describe('AIMW-SYNC-7071DA172F AppFormActions SaveClickedAsync', () => {
    it('dispatches save once, preserves busy/disabled fail-closed behavior, and exposes dirty state', async () => {
        let release!: () => void;
        const save = vi.fn(() => new Promise<void>((resolve) => { release = resolve; }));

        const view = render(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                dirty
                hint="Review before sync"
                saveText="Save, test and synchronize"
                onSave={save}
            />,
        );

        const saveButton = screen.getByRole('button', { name: 'Save, test and synchronize' });
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
        expect(view.container.querySelector('[data-canonical-operation="AIMW-SYNC-7071DA172F"]')).not.toBeNull();

        fireEvent.click(saveButton);
        fireEvent.click(saveButton);
        expect(save).toHaveBeenCalledTimes(1);
        release();
        await waitFor(() => expect(save).toHaveBeenCalledTimes(1));

        view.rerender(
            <SyncFormActionsControl
                context={context()}
                busy
                disabled
                saveText="Save"
                savingText="Saving"
                onSave={save}
            />,
        );
        expect(screen.getByRole('button', { name: 'Saving' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Saving' }));
        expect(save).toHaveBeenCalledTimes(1);
    });

    it('dispatches cancel only when available and hides the control without sites.manage', () => {
        const cancel = vi.fn();
        const first = render(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                saveText="Save"
                cancelText="Previous"
                onSave={() => undefined}
                onCancel={cancel}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
        expect(cancel).toHaveBeenCalledTimes(1);
        first.unmount();

        render(
            <SyncFormActionsControl
                context={context(['tenant.view'])}
                busy={false}
                saveText="Save"
                onSave={() => undefined}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Save' })).toBeNull();
    });

    it('binds AIMW-SYNC-14DAFE7D73 to CancelClickedAsync and preserves its independent guards', async () => {
        let release!: () => void;
        const save = vi.fn(() => new Promise<void>((resolve) => { release = resolve; }));
        const cancel = vi.fn();

        const view = render(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                saveText="Save"
                cancelText="Cancel"
                onSave={save}
                onCancel={cancel}
            />,
        );

        const cancelButton = screen.getByRole('button', { name: 'Cancel' });
        expect(SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID).toBe('AIMW-SYNC-14DAFE7D73');
        expect(cancelButton).toHaveAttribute('data-canonical-operation', SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID);
        expect(cancelButton).toHaveAttribute('data-source-handler', 'CancelClickedAsync');

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        fireEvent.click(cancelButton);
        expect(save).toHaveBeenCalledTimes(1);
        expect(cancel).toHaveBeenCalledTimes(1);

        release();
        await waitFor(() => expect(save).toHaveBeenCalledTimes(1));

        view.rerender(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                cancelDisabled
                saveText="Save"
                cancelText="Cancel"
                onSave={() => undefined}
                onCancel={cancel}
            />,
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();

        view.rerender(
            <SyncFormActionsControl
                context={context()}
                busy
                saveText="Save"
                cancelText="Cancel"
                onSave={() => undefined}
                onCancel={cancel}
            />,
        );
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();

        view.rerender(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                showCancel={false}
                saveText="Save"
                cancelText="Cancel"
                onSave={() => undefined}
                onCancel={cancel}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Cancel' })).toBeNull();
    });

    it('uses the exact canonical operation identity for the source-equivalent wrapper', () => {
        expect(SYNC_APP_FORM_ACTIONS_OPERATION_ID).toBe('AIMW-SYNC-7071DA172F');
    });
});
