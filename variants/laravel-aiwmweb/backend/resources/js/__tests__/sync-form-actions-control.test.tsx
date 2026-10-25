// Canonical PR CI anchors: save AIMW-SYNC-7071DA172F and cancel AIMW-SYNC-14DAFE7D73 remain bound to AppFormActions.
import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { FrontendContext } from '../core';
import {
    SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID,
    SYNC_APP_FORM_ACTIONS_OPERATION_ID,
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

    it('binds CancelClickedAsync to the exact cancel operation with source-equivalent independent guards', async () => {
        let release!: () => void;
        const save = vi.fn(() => new Promise<void>((resolve) => { release = resolve; }));
        const cancel = vi.fn();

        const first = render(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                saveText="Save"
                cancelText="Previous"
                onSave={save}
                onCancel={cancel}
            />,
        );

        const previous = screen.getByRole('button', { name: 'Previous' });
        expect(previous).toHaveAttribute('data-canonical-operation', SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID);
        expect(previous).toHaveAttribute('data-source-handler', 'CancelClickedAsync');

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        fireEvent.click(previous);
        expect(save).toHaveBeenCalledTimes(1);
        expect(cancel).toHaveBeenCalledTimes(1);
        release();
        await waitFor(() => expect(save).toHaveBeenCalledTimes(1));

        first.rerender(
            <SyncFormActionsControl
                context={context()}
                busy
                saveText="Save"
                cancelText="Previous"
                onSave={() => undefined}
                onCancel={cancel}
            />,
        );
        expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Previous' }));
        expect(cancel).toHaveBeenCalledTimes(1);

        first.rerender(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                cancelDisabled
                saveText="Save"
                cancelText="Previous"
                onSave={() => undefined}
                onCancel={cancel}
            />,
        );
        expect(screen.getByRole('button', { name: 'Previous' })).toBeDisabled();

        first.rerender(
            <SyncFormActionsControl
                context={context()}
                busy={false}
                showCancel={false}
                saveText="Save"
                cancelText="Previous"
                onSave={() => undefined}
                onCancel={cancel}
            />,
        );
        expect(screen.queryByRole('button', { name: 'Previous' })).toBeNull();
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

    it('uses the exact canonical operation identity for the source-equivalent wrapper', () => {
        expect(SYNC_APP_FORM_ACTIONS_OPERATION_ID).toBe('AIMW-SYNC-7071DA172F');
        expect(SYNC_APP_FORM_ACTIONS_CANCEL_OPERATION_ID).toBe('AIMW-SYNC-14DAFE7D73');
    });
});
