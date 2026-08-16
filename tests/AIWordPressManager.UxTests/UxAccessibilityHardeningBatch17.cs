using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch17
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
              const referencedText = el => (el.getAttribute('aria-labelledby') || '')
                .trim().split(/\s+/).filter(Boolean)
                .map(id => document.getElementById(id))
                .filter(Boolean)
                .map(target => (
                  target.getAttribute('aria-label') ||
                  target.innerText ||
                  target.textContent ||
                  target.getAttribute('title') ||
                  ''
                ).trim())
                .filter(Boolean)
                .join(' ');
              const authorAccessibleName = el => (
                el.getAttribute('aria-label') ||
                referencedText(el) ||
                el.getAttribute('title') ||
                ''
              ).trim();
              const contentAccessibleName = el => (
                authorAccessibleName(el) ||
                el.innerText ||
                el.textContent ||
                ''
              ).trim();
              const controlledTargets = el => (el.getAttribute('aria-controls') || '')
                .trim().split(/\s+/).filter(Boolean)
                .map(id => document.getElementById(id))
                .filter(Boolean);
              const popupRole = el => (el.getAttribute('role') || '').trim().toLowerCase();
              const supportedPopupRoles = new Set(['listbox', 'tree', 'grid', 'dialog']);

              // UX010-HARD-136
              document.querySelectorAll('[role="heading"]').forEach(el => {
                if (visible(el) && !contentAccessibleName(el))
                  issues.push('visible role=heading missing accessible name');
              });

              // UX010-HARD-137
              document.querySelectorAll('[role="menuitemcheckbox"]').forEach(el => {
                if (!visible(el)) return;
                const checked = (el.getAttribute('aria-checked') || '').trim().toLowerCase();
                if (!['true', 'false', 'mixed'].includes(checked))
                  issues.push('role=menuitemcheckbox requires aria-checked: true|false|mixed');
              });

              // UX010-HARD-138
              document.querySelectorAll('[role="menuitemradio"]').forEach(el => {
                if (!visible(el)) return;
                const checked = (el.getAttribute('aria-checked') || '').trim().toLowerCase();
                if (!['true', 'false'].includes(checked))
                  issues.push('role=menuitemradio requires aria-checked: true|false');
              });

              // UX010-HARD-139
              document.querySelectorAll('[role="combobox"]').forEach(el => {
                if (!visible(el)) return;
                const raw = (el.getAttribute('aria-controls') || '').trim();
                const ids = raw.split(/\s+/).filter(Boolean);
                const targets = controlledTargets(el);
                const popup = targets.find(target => supportedPopupRoles.has(popupRole(target)));
                if (!ids.length || targets.length !== ids.length || !popup)
                  issues.push('role=combobox requires resolving aria-controls popup');
              });

              // UX010-HARD-140
              document.querySelectorAll('[role="combobox"]').forEach(el => {
                if (!visible(el)) return;
                const popup = controlledTargets(el).find(target => supportedPopupRoles.has(popupRole(target)));
                if (!popup) return;
                const role = popupRole(popup);
                const hasPopup = (el.getAttribute('aria-haspopup') || '').trim().toLowerCase();
                if (role === 'listbox') {
                  if (hasPopup && hasPopup !== 'listbox')
                    issues.push('role=combobox aria-haspopup must match controlled popup role');
                } else if (hasPopup !== role) {
                  issues.push('role=combobox aria-haspopup must match controlled popup role');
                }
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
