using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch8
{
    public static async Task<IReadOnlyList<string>> IssuesAsync(IPage page)
    {
        var issues = await page.EvaluateAsync<string[]>("""
            () => {
              const issues = [];
              const visible = el => {
                const style = getComputedStyle(el);
                const rect = el.getBoundingClientRect();
                return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
              };
              const referenceText = (el, attribute) => (el.getAttribute(attribute) || '')
                .trim().split(/\s+/).filter(Boolean)
                .map(id => document.getElementById(id))
                .filter(Boolean)
                .map(target => (target.innerText || target.textContent || '').trim())
                .filter(Boolean)
                .join(' ');
              const labelText = el => el.labels ? [...el.labels].map(x => (x.innerText || x.textContent || '').trim()).filter(Boolean).join(' ') : '';
              const explicitName = el => (el.getAttribute('aria-label') || referenceText(el, 'aria-labelledby') || el.getAttribute('title') || '').trim();
              const nativeName = el => (explicitName(el) || labelText(el)).trim();

              // UX010-HARD-071
              document.querySelectorAll('fieldset').forEach(fieldset => {
                if (!visible(fieldset) || !fieldset.querySelector('input:not([type="hidden"]),select,textarea,button')) return;
                const legend = fieldset.querySelector(':scope > legend');
                const legendText = (legend?.innerText || legend?.textContent || '').trim();
                if (!legendText && !explicitName(fieldset)) issues.push('visible fieldset with controls missing legend or accessible name');
              });

              // UX010-HARD-072
              document.querySelectorAll('optgroup').forEach(group => {
                if (!(group.getAttribute('label') || '').trim()) issues.push('optgroup missing non-empty label');
              });

              // UX010-HARD-073
              document.querySelectorAll('table > caption').forEach(caption => {
                if (!(caption.innerText || caption.textContent || '').trim()) issues.push('table caption must not be empty');
              });

              // UX010-HARD-074
              document.querySelectorAll('th[scope]').forEach(header => {
                const value = (header.getAttribute('scope') || '').trim().toLowerCase();
                if (!new Set(['row', 'col', 'rowgroup', 'colgroup']).has(value)) issues.push(`invalid th scope value: ${value || '(empty)'}`);
              });

              // UX010-HARD-075
              document.querySelectorAll('meter').forEach(el => {
                if (visible(el) && !nativeName(el)) issues.push('visible meter missing accessible name');
              });

              // UX010-HARD-076
              document.querySelectorAll('progress').forEach(el => {
                if (visible(el) && !nativeName(el)) issues.push('visible progress missing accessible name');
              });

              // UX010-HARD-077
              document.querySelectorAll('output').forEach(el => {
                if (visible(el) && !nativeName(el)) issues.push('visible output missing accessible name');
              });

              // UX010-HARD-078
              document.querySelectorAll('summary').forEach(el => {
                if (visible(el) && !(el.innerText || el.textContent || '').trim() && !explicitName(el))
                  issues.push('visible summary missing accessible name');
              });

              // UX010-HARD-079
              const forms = [...document.querySelectorAll('form')].filter(form =>
                visible(form) && form.querySelector('input:not([type="hidden"]),select,textarea,button'));
              if (forms.length > 1) forms.forEach(form => {
                if (!explicitName(form)) issues.push('multiple visible forms require accessible names');
              });

              // UX010-HARD-080
              document.querySelectorAll('input[type="radio"][name]').forEach(radio => {
                const name = (radio.getAttribute('name') || '').trim();
                if (!name) return;
                const escaped = CSS.escape(name);
                const group = [...document.querySelectorAll(`input[type="radio"][name="${escaped}"]`)].filter(visible);
                if (group.length > 1 && !group.some(item => nativeName(item)))
                  issues.push(`visible native radio group has no labeled option: ${name}`);
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
