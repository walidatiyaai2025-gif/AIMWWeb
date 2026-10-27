import React, { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ActionDialog } from '../components';
import { AppDialogCloseControl, APP_DIALOG_CLOSE_OPERATION_ID } from '../app-dialog-close-control';
import { LocaleProvider } from '../i18n';
import type { ActionContract } from '../core';

const contract: ActionContract = { endpoint: '/api/v1/tenants/alpha/sites', method: 'POST', capability: 'sites.manage',
    fields: [{ key: 'name', type: 'text', label: { en: 'Name', ar: 'الاسم' }, required: true }] };
function Harness({ submit, busy = false }: { submit: () => void; busy?: boolean }) {
    const [open, setOpen] = useState(true);
    return <LocaleProvider><button onClick={() => setOpen(true)}>Reopen</button>
        <ActionDialog open={open} actionKey="sites.connect" contract={contract} onClose={() => setOpen(false)}
            onSubmit={submit} busy={busy} serverErrors={{}} /></LocaleProvider>;
}
describe('AIMW-SYNC-ABE31C5C4E shared AppDialog CloseAsync', () => {
    it('closes the real action dialog without submitting or requesting, and reopening clears the draft', () => {
        const submit = vi.fn(); const request = vi.spyOn(globalThis, 'fetch');
        render(<Harness submit={submit} />);
        fireEvent.change(screen.getByRole('textbox', { name: /Name/ }), { target: { value: 'Unsaved draft' } });
        const close = document.querySelector(`[data-canonical-operation="${APP_DIALOG_CLOSE_OPERATION_ID}"]`)!;
        expect(close).toHaveAttribute('type', 'button');
        fireEvent.click(close);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(submit).not.toHaveBeenCalled(); expect(request).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        expect(screen.getByRole('textbox', { name: /Name/ })).toHaveValue('');
        request.mockRestore();
    });
    it('preserves the source close callback even while a form operation is busy', () => {
        const submit = vi.fn(); render(<Harness submit={submit} busy />);
        fireEvent.click(document.querySelector(`[data-canonical-operation="${APP_DIALOG_CLOSE_OPERATION_ID}"]`)!);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument(); expect(submit).not.toHaveBeenCalled();
    });
    it('supports source ShowCloseButton and accessible labels without publishing a hidden action', () => {
        const close = vi.fn(); const v = render(<AppDialogCloseControl onClose={close} showCloseButton={false} />);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        v.rerender(<AppDialogCloseControl onClose={close} ariaLabel="إغلاق الحوار" />);
        fireEvent.click(screen.getByRole('button', { name: 'إغلاق الحوار' })); expect(close).toHaveBeenCalledOnce();
    });
    it('does not render a close control for a closed or missing action contract', () => {
        render(<LocaleProvider><ActionDialog open={false} actionKey="sites.connect" contract={null} onClose={vi.fn()}
            onSubmit={vi.fn()} busy={false} serverErrors={{}} /></LocaleProvider>);
        expect(document.querySelector(`[data-canonical-operation="${APP_DIALOG_CLOSE_OPERATION_ID}"]`)).toBeNull();
    });
});
