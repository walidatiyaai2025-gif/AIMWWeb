import { beforeEach, describe, expect, it, vi } from 'vitest';
import { wireAiPromptNewTemplate } from '../../../public/js/ai-prompt-new-template.js';

describe('AIMW-AI-825B2F5A38 New template visible control', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <button type="button" data-ai-prompt-new-template aria-expanded="false">New template</button>
            <p data-ai-prompt-transient>Previous success</p>
            <section data-ai-prompt-new-editor data-state="idle" hidden>
                <p data-ai-prompt-new-status hidden></p>
                <input data-ai-prompt-new-key value="old.key">
                <input data-ai-prompt-new-title value="Old title">
                <textarea data-ai-prompt-new-system>Old system</textarea>
                <textarea data-ai-prompt-new-user>Old user</textarea>
                <input data-ai-prompt-new-enabled type="checkbox">
            </section>
        `;
        vi.restoreAllMocks();
    });

    it('resets the editor into a truthful blank new-template state without network activity', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);
        wireAiPromptNewTemplate(document);

        const button = document.querySelector('[data-ai-prompt-new-template]');
        const editor = document.querySelector('[data-ai-prompt-new-editor]');
        const status = document.querySelector('[data-ai-prompt-new-status]');
        const key = document.querySelector('[data-ai-prompt-new-key]');
        const enabled = document.querySelector('[data-ai-prompt-new-enabled]');

        button.click();

        expect(editor.hidden).toBe(false);
        expect(editor.dataset.state).toBe('new');
        expect(button.getAttribute('aria-expanded')).toBe('true');
        expect(key.value).toBe('');
        expect(document.querySelector('[data-ai-prompt-new-title]').value).toBe('');
        expect(document.querySelector('[data-ai-prompt-new-system]').value).toBe('');
        expect(document.querySelector('[data-ai-prompt-new-user]').value).toBe('');
        expect(enabled.checked).toBe(true);
        expect(document.querySelector('[data-ai-prompt-transient]').hidden).toBe(true);
        expect(status.hidden).toBe(false);
        expect(status.textContent).toContain('nothing has been persisted');
        expect(document.activeElement).toBe(key);
        expect(fetchSpy).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
    });

    it('can be invoked repeatedly and clears draft edits every time', () => {
        wireAiPromptNewTemplate(document);
        const button = document.querySelector('[data-ai-prompt-new-template]');
        const key = document.querySelector('[data-ai-prompt-new-key]');
        const userPrompt = document.querySelector('[data-ai-prompt-new-user]');

        button.click();
        key.value = 'draft.key';
        userPrompt.value = 'Unsaved draft';
        button.click();

        expect(key.value).toBe('');
        expect(userPrompt.value).toBe('');
        expect(document.querySelector('[data-ai-prompt-new-enabled]').checked).toBe(true);
    });
});
