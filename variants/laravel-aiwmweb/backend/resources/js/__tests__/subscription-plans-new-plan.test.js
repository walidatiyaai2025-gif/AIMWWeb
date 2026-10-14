import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('Subscription Plans New plan browser behavior', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        document.documentElement.lang = 'en';
        document.body.innerHTML = `
            <button type="button" data-new-plan>New plan</button>
            <p data-new-plan-feedback>Previous success</p>
            <section data-new-plan-feedback>Previous validation error</section>
            <p data-clone-current-status>Previous clone status</p>
            <form data-plan-create-form>
                <input name="code" value="old-code">
                <input name="name_en" value="Old English">
                <input name="name_ar" value="قديم">
                <textarea name="description_en">Old description</textarea>
                <textarea name="description_ar">وصف قديم</textarea>
                <select name="billing_interval"><option value="Monthly">Monthly</option><option value="Yearly" selected>Yearly</option></select>
                <input name="price" value="99.99">
                <input name="currency" value="KWD">
                <input name="trial_days" value="30">
                <input name="grace_period_days" value="30">
                <input name="sort_order" value="999">
                <input type="hidden" name="is_enabled" value="0">
                <input type="checkbox" name="is_enabled" value="1">
                <input name="gateway_product_id" value="PROD-secret">
                <input name="gateway_plan_id" value="P-secret">
            </form>
        `;

        Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
            configurable: true,
            value: vi.fn(),
        });
    });

    async function loadControl() {
        await import('../../../public/js/subscription-plans-new-plan.js');
    }

    it('resets the create editor to the exact source defaults without request or submit', async () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        const form = document.querySelector('[data-plan-create-form]');
        const submitSpy = vi.fn((event) => event.preventDefault());
        form.addEventListener('submit', submitSpy);

        await loadControl();
        document.querySelector('[data-new-plan]').click();

        const value = (name) => form.querySelector(`[name="${name}"]`).value;
        expect(value('code')).toBe('');
        expect(value('name_en')).toBe('');
        expect(value('name_ar')).toBe('');
        expect(value('description_en')).toBe('');
        expect(value('description_ar')).toBe('');
        expect(value('billing_interval')).toBe('Monthly');
        expect(value('price')).toBe('0.00');
        expect(value('currency')).toBe('USD');
        expect(value('trial_days')).toBe('0');
        expect(value('grace_period_days')).toBe('7');
        expect(value('sort_order')).toBe('100');
        expect(value('gateway_product_id')).toBe('');
        expect(value('gateway_plan_id')).toBe('');
        expect(form.querySelector('input[name="is_enabled"][type="checkbox"]').checked).toBe(true);

        for (const feedback of document.querySelectorAll('[data-new-plan-feedback]')) {
            expect(feedback.hidden).toBe(true);
        }
        const cloneStatus = document.querySelector('[data-clone-current-status]');
        expect(cloneStatus.hidden).toBe(true);
        expect(cloneStatus.textContent).toBe('');
        expect(document.activeElement).toBe(form.querySelector('[name="code"]'));
        expect(HTMLElement.prototype.scrollIntoView).toHaveBeenCalledOnce();
        expect(submitSpy).not.toHaveBeenCalled();
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('is repeatable and discards unsaved draft edits on every click', async () => {
        await loadControl();
        const button = document.querySelector('[data-new-plan]');
        const form = document.querySelector('[data-plan-create-form]');

        button.click();
        form.querySelector('[name="code"]').value = 'unsaved-code';
        form.querySelector('[name="name_en"]').value = 'Unsaved draft';
        form.querySelector('input[name="is_enabled"][type="checkbox"]').checked = false;

        button.click();

        expect(form.querySelector('[name="code"]').value).toBe('');
        expect(form.querySelector('[name="name_en"]').value).toBe('');
        expect(form.querySelector('input[name="is_enabled"][type="checkbox"]').checked).toBe(true);
    });
});
