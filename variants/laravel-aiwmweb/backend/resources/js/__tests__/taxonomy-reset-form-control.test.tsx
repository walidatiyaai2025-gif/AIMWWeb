import React, { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ActionDialog, TAXONOMY_RESET_FORM_OPERATION_ID } from '../components';
import { type ActionContract } from '../core';
import { LocaleProvider } from '../i18n';

const contract: ActionContract = {
    endpoint: '/api/v1/tenants/alpha/sites/17/taxonomy',
    method: 'POST',
    capability: 'taxonomy.manage',
    fields: [
        { key: 'taxonomy', type: 'select', label: { en: 'Taxonomy', ar: 'التصنيف' }, required: true, options: [
            { value: 'category', label: { en: 'Category', ar: 'تصنيف' } },
            { value: 'post_tag', label: { en: 'Tag', ar: 'وسم' } },
        ] },
        { key: 'name', type: 'text', label: { en: 'Name', ar: 'الاسم' }, required: true },
        { key: 'slug', type: 'text', label: { en: 'Slug', ar: 'الرابط المختصر' } },
    ],
};

function Harness({ actionKey = 'taxonomy.manage', onSubmit = () => undefined }: { actionKey?: string; onSubmit?: (values: Record<string, string | number>) => void }) {
    const [open, setOpen] = useState(true);

    return (
        <LocaleProvider>
            <button type="button" onClick={() => setOpen(true)}>Reopen</button>
            <ActionDialog
                open={open}
                actionKey={actionKey}
                contract={contract}
                onClose={() => setOpen(false)}
                onSubmit={onSubmit}
                busy={false}
                serverErrors={{}}
            />
        </LocaleProvider>
    );
}

describe('AIMW-TAXO-5D39DA68B7 taxonomy ResetFormClicked parity', () => {
    it('marks only the taxonomy manage Cancel control and resets local draft state without submitting', () => {
        const onSubmit = vi.fn() as (values: Record<string, string | number>) => void;
        render(<Harness onSubmit={onSubmit} />);

        const name = screen.getByRole('textbox', { name: /Name/ });
        fireEvent.change(name, { target: { value: 'Draft taxonomy term' } });
        expect(name).toHaveValue('Draft taxonomy term');

        const cancel = screen.getByRole('button', { name: 'Cancel' });
        expect(cancel).toHaveAttribute('data-canonical-operation', TAXONOMY_RESET_FORM_OPERATION_ID);
        expect(TAXONOMY_RESET_FORM_OPERATION_ID).toBe('AIMW-TAXO-5D39DA68B7');

        fireEvent.click(cancel);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Reopen' }));
        expect(screen.getByRole('textbox', { name: /Name/ })).toHaveValue('');
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('does not claim any unrelated dialog close control', () => {
        render(<Harness actionKey="sites.connect" />);

        const closes = screen.getAllByRole('button', { name: 'Close' });
        expect(closes.length).toBeGreaterThan(0);
        closes.forEach((close) => {
            expect(close).not.toHaveAttribute('data-canonical-operation');
        });
        expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument();
    });
});
