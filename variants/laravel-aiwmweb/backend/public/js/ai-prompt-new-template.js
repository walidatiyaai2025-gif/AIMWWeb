const READY_MESSAGE = 'New template draft ready. Enter prompt details; nothing has been persisted.';

export function wireAiPromptNewTemplate(root = document) {
    const button = root.querySelector('[data-ai-prompt-new-template]');
    const editor = root.querySelector('[data-ai-prompt-new-editor]');
    const status = root.querySelector('[data-ai-prompt-new-status]');
    const key = root.querySelector('[data-ai-prompt-new-key]');
    const title = root.querySelector('[data-ai-prompt-new-title]');
    const systemPrompt = root.querySelector('[data-ai-prompt-new-system]');
    const userPrompt = root.querySelector('[data-ai-prompt-new-user]');
    const enabled = root.querySelector('[data-ai-prompt-new-enabled]');

    if (!button || !editor || !status || !key || !title || !systemPrompt || !userPrompt || !enabled) {
        return;
    }

    button.addEventListener('click', () => {
        key.value = '';
        title.value = '';
        systemPrompt.value = '';
        userPrompt.value = '';
        enabled.checked = true;

        root.querySelectorAll('[data-ai-prompt-transient]').forEach((node) => {
            node.hidden = true;
        });

        editor.hidden = false;
        editor.dataset.state = 'new';
        button.setAttribute('aria-expanded', 'true');
        status.textContent = READY_MESSAGE;
        status.hidden = false;
        key.focus();
    });
}

wireAiPromptNewTemplate();
