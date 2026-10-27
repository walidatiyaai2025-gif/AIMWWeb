import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ActionDialog, WORDPRESS_USERS_RESET_FORM_OPERATION_ID } from '../components';
import { LocaleProvider } from '../i18n';

const contract = {
    operation_id: 'AIMW-BILL-D307963B59',
    method: 'POST',
    path: '/api/v1/tenants/{tenant}/sites/{site}/users',
    fields: [{ key: 'username', label: { en: 'Username', ar: 'اسم المستخدم' }, type: 'text', required: true }],
};

describe('AIMW-CONT-10AFCDF334 WordPress Users ResetFormClicked', () => {
    it('clears local editor state and closes without invoking save', () => {
        let closes = 0;
        let submits = 0;
        const { rerender } = render(
            <LocaleProvider>
                <ActionDialog open actionKey="wordpress-users.save" contract={contract as never} onClose={() => closes++} onSubmit={() => submits++} busy={false} serverErrors={{}} />
            </LocaleProvider>,
        );
        fireEvent.change(screen.getByLabelText('Username *'), { target: { value: 'draft-user' } });
        const cancel = screen.getByRole('button', { name: 'Cancel' });
        expect(cancel).toHaveAttribute('data-canonical-operation', WORDPRESS_USERS_RESET_FORM_OPERATION_ID);
        fireEvent.click(cancel);
        expect(closes).toBe(1);
        expect(submits).toBe(0);

        rerender(
            <LocaleProvider>
                <ActionDialog open actionKey="wordpress-users.save" contract={contract as never} onClose={() => closes++} onSubmit={() => submits++} busy={false} serverErrors={{}} />
            </LocaleProvider>,
        );
        expect(screen.getByLabelText('Username *')).toHaveValue('');
    });
});
