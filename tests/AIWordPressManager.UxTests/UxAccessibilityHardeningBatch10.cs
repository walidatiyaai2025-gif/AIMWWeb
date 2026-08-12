using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch10
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
              const explicitName = el => (
                el.getAttribute('aria-label') || referenceText(el, 'aria-labelledby') || el.getAttribute('title') || ''
              ).trim();

              // UX010-HARD-091
              document.querySelectorAll('[id]').forEach(el => {
                if (!(el.getAttribute('id') || '').trim()) issues.push('id attribute must not be empty');
              });

              // UX010-HARD-092
              document.querySelectorAll('[id]').forEach(el => {
                const id = el.getAttribute('id') || '';
                if (/\s/.test(id)) issues.push(`id attribute must not contain whitespace: ${id}`);
              });

              // UX010-HARD-093
              document.querySelectorAll('a[href^="#"]').forEach(link => {
                const href = (link.getAttribute('href') || '').trim();
                if (href.length <= 1) return;
                let id;
                try { id = decodeURIComponent(href.slice(1)); } catch { id = href.slice(1); }
                if (id && !document.getElementById(id)) issues.push(`same-page fragment references missing id: ${id}`);
              });

              // UX010-HARD-094
              document.querySelectorAll('[tabindex]').forEach(el => {
                const raw = (el.getAttribute('tabindex') || '').trim();
                if (!/^[+-]?\d+$/.test(raw)) issues.push(`tabindex must be an integer: ${raw || '(empty)'}`);
              });

              // UX010-HARD-095
              const autofocus = [...document.querySelectorAll('[autofocus]')].filter(visible);
              if (autofocus.length > 1) issues.push(`multiple visible autofocus targets detected: ${autofocus.length}`);

              // UX010-HARD-096
              document.querySelectorAll('[autofocus]').forEach(el => {
                if (el.matches(':disabled') || el.hidden || el.getAttribute('aria-hidden') === 'true')
                  issues.push('autofocus target must not be disabled or hidden');
              });

              // UX010-HARD-097
              document.querySelectorAll('[accesskey]').forEach(el => {
                const tokens = (el.getAttribute('accesskey') || '').trim().split(/\s+/).filter(Boolean);
                if (!tokens.length || tokens.some(token => [...token].length !== 1))
                  issues.push(`accesskey must contain one-character tokens: ${(el.getAttribute('accesskey') || '').trim() || '(empty)'}`);
              });

              // UX010-HARD-098
              const seenAccessKeys = new Map();
              document.querySelectorAll('[accesskey]').forEach(el => {
                if (!visible(el)) return;
                const tokens = (el.getAttribute('accesskey') || '').trim().split(/\s+/).filter(Boolean);
                tokens.forEach(token => {
                  const key = token.toLocaleLowerCase();
                  if (seenAccessKeys.has(key)) issues.push(`duplicate visible accesskey token: ${token}`);
                  else seenAccessKeys.set(key, el);
                });
              });

              // UX010-HARD-099
              document.querySelectorAll('[contenteditable]').forEach(el => {
                const value = (el.getAttribute('contenteditable') || '').trim().toLowerCase();
                const editable = value === '' || value === 'true' || value === 'plaintext-only';
                if (editable && visible(el) && !explicitName(el)) issues.push('visible contenteditable region missing accessible name');
              });

              // UX010-HARD-100
              document.querySelectorAll('[onclick]').forEach(el => {
                if (!visible(el)) return;
                const nativeInteractive = el.matches('a[href],button,input:not([type="hidden"]),select,textarea,summary');
                const roleInteractive = new Set(['button','link','checkbox','radio','switch','tab','menuitem','menuitemcheckbox','menuitemradio','option']).has((el.getAttribute('role') || '').trim().toLowerCase());
                if (!nativeInteractive && !roleInteractive && el.tabIndex < 0)
                  issues.push('visible inline-click target is not keyboard operable');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
