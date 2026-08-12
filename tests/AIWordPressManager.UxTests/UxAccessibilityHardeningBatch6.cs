using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch6
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
              const accessibleName = el => (
                el.getAttribute('aria-label') || referenceText(el, 'aria-labelledby') || labelText(el) ||
                el.getAttribute('title') || el.innerText || el.textContent || ''
              ).trim();
              const disabled = el => el.matches(':disabled') || el.getAttribute('aria-disabled') === 'true';

              // UX010-HARD-051
              document.querySelectorAll('[role="link"]').forEach(el => {
                if (visible(el) && !accessibleName(el)) issues.push('visible role=link missing accessible name');
              });

              // UX010-HARD-052
              document.querySelectorAll('[role="link"]').forEach(el => {
                if (visible(el) && !disabled(el) && el.tabIndex < 0) issues.push('enabled role=link is not keyboard focusable');
              });

              // UX010-HARD-053
              document.querySelectorAll('[role="menuitem"],[role="menuitemcheckbox"],[role="menuitemradio"]').forEach(el => {
                if (visible(el) && !accessibleName(el)) issues.push('visible menu item missing accessible name');
              });

              // UX010-HARD-054
              document.querySelectorAll('[role="menu"]').forEach(menu => {
                if (!visible(menu)) return;
                const items = [...menu.querySelectorAll('[role="menuitem"],[role="menuitemcheckbox"],[role="menuitemradio"]')]
                  .filter(el => visible(el) && !disabled(el));
                if (items.length && !items.some(el => el.tabIndex >= 0)) issues.push('visible menu has no keyboard entry item');
              });

              // UX010-HARD-055
              document.querySelectorAll('[role="tab"]').forEach(el => {
                if (visible(el) && !accessibleName(el)) issues.push('visible role=tab missing accessible name');
              });

              // UX010-HARD-056
              document.querySelectorAll('[role="tab"]').forEach(el => {
                const value = (el.getAttribute('aria-selected') || '').trim().toLowerCase();
                if (value !== 'true' && value !== 'false') issues.push(`role=tab requires boolean aria-selected: ${value || '(empty)'}`);
              });

              // UX010-HARD-057
              document.querySelectorAll('[role="tab"][aria-selected="true"]').forEach(el => {
                if (visible(el) && !disabled(el) && el.tabIndex < 0) issues.push('selected visible tab is not keyboard focusable');
              });

              // UX010-HARD-058
              document.querySelectorAll('[role="option"]').forEach(el => {
                if (visible(el) && !accessibleName(el)) issues.push('visible role=option missing accessible name');
              });

              // UX010-HARD-059
              document.querySelectorAll('[role="switch"]').forEach(el => {
                const value = (el.getAttribute('aria-checked') || '').trim().toLowerCase();
                if (value !== 'true' && value !== 'false') issues.push(`role=switch requires boolean aria-checked: ${value || '(empty)'}`);
              });

              // UX010-HARD-060
              document.querySelectorAll('[role="combobox"]').forEach(el => {
                if (visible(el) && !accessibleName(el)) issues.push('visible role=combobox missing accessible name');
                const expanded = (el.getAttribute('aria-expanded') || '').trim().toLowerCase();
                if (expanded !== 'true' && expanded !== 'false') issues.push(`role=combobox requires boolean aria-expanded: ${expanded || '(empty)'}`);
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
