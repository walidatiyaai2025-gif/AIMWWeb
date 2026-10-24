import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ActionDialog, TAXONOMY_SAVE_SUBMIT_OPERATION_ID } from '../components';
import { type ActionContract } from '../core';
import { LocaleProvider } from '../i18n';

const taxonomyContract: ActionContract = {
    endpoint: '/api/v1/tenants/alpha/sites/17/taxonomy',
    method: 'POST',
    capability: 'taxonomy.manage',
    fields: [
        {
            key: 'taxonomy',
            type: 'select',
            label: { en: 'Taxonomy', ar: 'التصنيف' },
            required: true,
            options: [
                { value: 'category', label: { en: 'Category', ar: 'تصنيف' } },
                { value: 'post_tag', label: { en: 'Tag', ar: 'وسم' } },
            ],
        },
        { key: 'name', type: 'text', label: { en: 'Name', ar: 'الاسم' }, required: true },
        { key: 'slug', type: 'text', label: { en: 'Slug', ar: 'الرابط المختصر' } },
    ],
};

type SubmitHandler = (values: Record<string, string | number>) => void;

function Harness({
    actionKey = 'taxonomy.manage',
    busy = false,
    onSubmit = () => undefined,
}: {
    actionKey?: string;
    busy?: boolean;
    onSubmit?: SubmitHandler;
}) {
    return (
        <LocaleProvider>
            <ActionDialog
                open
                actionKey={actionKey}
                contract={taxonomyContract}
                onClose={vi.fn()}
                onSubmit={onSubmit}
                busy={busy}
                serverErrors={{}}
            />
        </LocaleProvider>
    );
}

describe('AIMW-TAXO-39CDD7EF81 taxonomy Save submit parity', () => {
    it('marks the taxonomy Save submit and forwards the validated draft exactly once', () => {
        const onSubmit = vi.fn<SubmitHandler>();
        render(<Harness onSubmit={onSubmit} />);

        const save = screen.getByRole('button', { name: 'Save' });
        expect(TAXONOMY_SAVE_SUBMIT_OPERATION_ID).toBe('AIMW-TAXO-39CDD7EF81');
        expect(save).toHaveAttribute('data-canonical-operation', TAXONOMY_SAVE_SUBMIT_OPERATION_ID);
        expect(save).toHaveAttribute('aria-busy', 'false');
        expect(save).not.toBeDisabled();

        fireEvent.change(screen.getByRole('combobox', { name: /Taxonomy/ }), { target: { value: 'category' } });
        fireEvent.change(screen.getByRole('textbox', { name: /Name/ }), { target: { value: 'News' } });
        fireEvent.click(save);

        expect(onSubmit).toHaveBeenCalledTimes(1);
        expect(onSubmit).toHaveBeenCalledWith({ taxonomy: 'category', name: 'News' });
    });

    it('keeps the Save control disabled and exposes Saving while the authoritative mutation is pending', () => {
        const onSubmit = vi.fn<SubmitHandler>();
        render(<Harness busy onSubmit={onSubmit} />);

        const save = screen.getByRole('button', { name: 'Saving' });
        expect(save).toBeDisabled();
        expect(save).toHaveAttribute('aria-busy', 'true');
        expect(save).toHaveAttribute('data-canonical-operation', TAXONOMY_SAVE_SUBMIT_OPERATION_ID);

        fireEvent.click(save);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('does not claim unrelated action-dialog submit controls', () => {
        render(<Harness actionKey="sites.connect" />);

        const submit = screen.getByRole('button', { name: 'Submit' });
        expect(submit).not.toHaveAttribute('data-canonical-operation');
        expect(screen.queryByRole('button', { name: 'Save' })).not.toBeInTheDocument();
    });
});
