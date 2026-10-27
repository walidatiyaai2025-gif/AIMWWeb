import React, { useState } from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    SITE_RELIABILITY_APPLY_FILTERS_OPERATION_ID,
    SiteReliabilityFiltersControl,
    siteReliabilityDefaultFilters,
    type SiteReliabilityFilterState,
} from '../site-reliability-filters-control';

function Harness({ busy = false, onApply = vi.fn() }: { busy?: boolean; onApply?: (value: SiteReliabilityFilterState) => void }) {
    const [value, setValue] = useState<SiteReliabilityFilterState>(siteReliabilityDefaultFilters());
    return (
        <SiteReliabilityFiltersControl
            locale="en"
            value={value}
            busy={busy}
            onChange={setValue}
            onApply={onApply}
        />
    );
}

describe('AIMW-CONT-40CD7F080F Site Reliability ApplyFilters', () => {
    it('starts with source-equivalent 30-day and one-operation defaults', () => {
        expect(siteReliabilityDefaultFilters()).toEqual({ periodDays: '30', minimumOperations: '1' });
        render(<Harness />);

        expect(screen.getByRole('combobox', { name: 'Period' })).toHaveValue('30');
        expect(screen.getByRole('combobox', { name: 'Minimum operations' })).toHaveValue('1');
    });

    it('applies only the supported source period and minimum-operation choices with the exact marker', () => {
        const onApply = vi.fn();
        render(<Harness onApply={onApply} />);

        fireEvent.change(screen.getByRole('combobox', { name: 'Period' }), { target: { value: '90' } });
        fireEvent.change(screen.getByRole('combobox', { name: 'Minimum operations' }), { target: { value: '5' } });

        const apply = screen.getByRole('button', { name: 'Apply' });
        expect(apply).toHaveAttribute('data-canonical-operation', SITE_RELIABILITY_APPLY_FILTERS_OPERATION_ID);
        expect(SITE_RELIABILITY_APPLY_FILTERS_OPERATION_ID).toBe('AIMW-CONT-40CD7F080F');

        fireEvent.click(apply);
        expect(onApply).toHaveBeenCalledWith({ periodDays: '90', minimumOperations: '5' });
    });

    it('suppresses duplicate activation while the authoritative read is busy', () => {
        const onApply = vi.fn();
        render(<Harness busy onApply={onApply} />);

        const apply = screen.getByRole('button', { name: 'Applying…' });
        expect(apply).toBeDisabled();
        expect(apply).toHaveAttribute('aria-busy', 'true');
        fireEvent.click(apply);
        expect(onApply).not.toHaveBeenCalled();
    });
});
