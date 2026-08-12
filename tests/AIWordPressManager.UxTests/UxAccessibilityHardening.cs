using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardening
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
              const referenceIds = (el, attribute) => (el.getAttribute(attribute) || '').trim().split(/\s+/).filter(Boolean);
              const referenceText = (el, attribute) => referenceIds(el, attribute)
                .map(id => document.getElementById(id))
                .filter(Boolean)
                .map(target => (target.innerText || target.textContent || '').trim())
                .filter(Boolean)
                .join(' ');
              const accessibleName = el => (
                el.getAttribute('aria-label') ||
                referenceText(el, 'aria-labelledby') ||
                el.getAttribute('title') ||
                ''
              ).trim();
              const validateReferences = (attribute, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const ids = referenceIds(el, attribute);
                  if (ids.length === 0) {
                    issues.push(`${message}: empty ${attribute}`);
                    return;
                  }
                  ids.forEach(id => {
                    if (!document.getElementById(id)) issues.push(`${message}: #${id}`);
                  });
                });
              };

              // UX010-HARD-001
              validateReferences('aria-labelledby', 'broken aria-labelledby reference');

              // UX010-HARD-002
              validateReferences('aria-describedby', 'broken aria-describedby reference');

              // UX010-HARD-003
              validateReferences('aria-controls', 'broken aria-controls reference');

              // UX010-HARD-004
              validateReferences('aria-owns', 'broken aria-owns reference');

              // UX010-HARD-005
              document.querySelectorAll('[role="button"]').forEach(el => {
                if (!visible(el) || el.getAttribute('aria-disabled') === 'true') return;
                if (el.tabIndex < 0) issues.push('role=button is not keyboard focusable');
              });

              // UX010-HARD-006
              document.querySelectorAll('[role="dialog"],[role="alertdialog"],dialog[open]').forEach(el => {
                if (!visible(el)) return;
                if (!accessibleName(el)) issues.push('visible dialog missing accessible name');
              });

              // UX010-HARD-007
              document.querySelectorAll('iframe').forEach(el => {
                if (!visible(el)) return;
                if (!(el.getAttribute('title') || '').trim()) issues.push('visible iframe missing title');
              });

              // UX010-HARD-008
              document.querySelectorAll('[aria-hidden="true"]').forEach(root => {
                const candidates = [];
                if (root.matches('a[href],button,input,select,textarea,[tabindex]')) candidates.push(root);
                candidates.push(...root.querySelectorAll('a[href],button,input,select,textarea,[tabindex]'));
                candidates.forEach(el => {
                  const disabled = el.matches(':disabled') || el.getAttribute('aria-disabled') === 'true';
                  if (!disabled && visible(el) && el.tabIndex >= 0)
                    issues.push('aria-hidden subtree contains focusable content');
                });
              });

              // UX010-HARD-009
              document.querySelectorAll('[role="img"]').forEach(el => {
                if (!visible(el)) return;
                if (!accessibleName(el)) issues.push('role=img missing accessible name');
              });

              // UX010-HARD-010
              const allowedStates = {
                'aria-expanded': new Set(['true', 'false']),
                'aria-selected': new Set(['true', 'false']),
                'aria-pressed': new Set(['true', 'false', 'mixed']),
                'aria-checked': new Set(['true', 'false', 'mixed'])
              };
              Object.entries(allowedStates).forEach(([attribute, allowed]) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const value = (el.getAttribute(attribute) || '').trim().toLowerCase();
                  if (!allowed.has(value)) issues.push(`invalid ${attribute} value: ${value || '(empty)'}`);
                });
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
