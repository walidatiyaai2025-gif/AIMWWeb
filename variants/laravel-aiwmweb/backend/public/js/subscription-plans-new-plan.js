(() => {
    'use strict';

    const createForm = document.querySelector('[data-plan-create-form]');
    const button = document.querySelector('[data-new-plan]');
    if (!(createForm instanceof HTMLFormElement) || !(button instanceof HTMLButtonElement)) return;

    const control = (name) => createForm.querySelector(`[name="${name}"]`);
    const setValue = (name, value) => {
        const element = control(name);
        if (element instanceof HTMLInputElement
            || element instanceof HTMLTextAreaElement
            || element instanceof HTMLSelectElement) {
            element.value = value;
        }
    };

    button.addEventListener('click', () => {
        setValue('code', '');
        setValue('name_en', '');
        setValue('name_ar', '');
        setValue('description_en', '');
        setValue('description_ar', '');
        setValue('billing_interval', 'Monthly');
        setValue('price', '0.00');
        setValue('currency', 'USD');
        setValue('trial_days', '0');
        setValue('grace_period_days', '7');
        setValue('sort_order', '100');
        setValue('gateway_product_id', '');
        setValue('gateway_plan_id', '');

        const enabled = createForm.querySelector('input[name="is_enabled"][type="checkbox"]');
        if (enabled instanceof HTMLInputElement) enabled.checked = true;

        for (const item of document.querySelectorAll('[data-new-plan-feedback]')) {
            if (item instanceof HTMLElement) item.hidden = true;
        }

        const cloneStatus = document.querySelector('[data-clone-current-status]');
        if (cloneStatus instanceof HTMLElement) {
            cloneStatus.textContent = '';
            cloneStatus.hidden = true;
        }

        const code = control('code');
        if (code instanceof HTMLInputElement) code.focus({ preventScroll: true });
        createForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
})();