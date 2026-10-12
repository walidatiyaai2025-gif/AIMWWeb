(() => {
    'use strict';

    const createForm = document.querySelector('[data-plan-create-form]');
    const status = document.querySelector('[data-clone-current-status]');
    if (!(createForm instanceof HTMLFormElement)) return;

    const field = (form, name) => form.querySelector(`[name="${name}"]`);
    const value = (form, name) => {
        const control = field(form, name);
        return control instanceof HTMLInputElement
            || control instanceof HTMLTextAreaElement
            || control instanceof HTMLSelectElement
            ? control.value
            : '';
    };
    const setValue = (form, name, next) => {
        const control = field(form, name);
        if (control instanceof HTMLInputElement
            || control instanceof HTMLTextAreaElement
            || control instanceof HTMLSelectElement) {
            control.value = next;
        }
    };

    for (const button of document.querySelectorAll('[data-clone-current]')) {
        if (!(button instanceof HTMLButtonElement)) continue;

        button.addEventListener('click', () => {
            const article = button.closest('[data-plan-code]');
            const editor = article?.querySelector('[data-plan-editor]');
            if (!(article instanceof HTMLElement) || !(editor instanceof HTMLFormElement)) return;

            const planCode = String(article.dataset.planCode ?? '').trim();
            if (!planCode) return;

            setValue(createForm, 'code', `${planCode}-copy`);
            setValue(createForm, 'name_en', `${value(editor, 'name_en')} Copy`);
            setValue(createForm, 'name_ar', `نسخة ${value(editor, 'name_ar')}`);
            setValue(createForm, 'description_en', value(editor, 'description_en'));
            setValue(createForm, 'description_ar', value(editor, 'description_ar'));
            setValue(createForm, 'billing_interval', value(editor, 'billing_interval'));
            setValue(createForm, 'price', value(editor, 'price'));
            setValue(createForm, 'currency', value(editor, 'currency'));
            setValue(createForm, 'trial_days', value(editor, 'trial_days'));
            setValue(createForm, 'grace_period_days', value(editor, 'grace_period_days'));
            setValue(createForm, 'sort_order', value(editor, 'sort_order'));

            const createEnabled = createForm.querySelector('input[name="is_enabled"][type="checkbox"]');
            if (createEnabled instanceof HTMLInputElement) createEnabled.checked = false;

            setValue(createForm, 'gateway_product_id', '');
            setValue(createForm, 'gateway_plan_id', '');

            if (status instanceof HTMLElement) {
                const language = document.documentElement.lang.toLowerCase();
                status.textContent = language === 'ar'
                    ? String(button.dataset.messageAr ?? '')
                    : String(button.dataset.messageEn ?? '');
                status.hidden = false;
            }

            const codeInput = field(createForm, 'code');
            if (codeInput instanceof HTMLInputElement) {
                codeInput.focus({ preventScroll: true });
            }
            createForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }
})();
