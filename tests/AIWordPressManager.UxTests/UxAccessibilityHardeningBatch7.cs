using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch7
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
              const contentName = el => (explicitName(el) || el.innerText || el.textContent || '').trim();
              const disabled = el => el.matches(':disabled') || el.getAttribute('aria-disabled') === 'true';

              // UX010-HARD-061
              document.querySelectorAll('[role="region"]').forEach(el => {
                if (visible(el) && !explicitName(el)) issues.push('visible role=region missing accessible name');
              });

              // UX010-HARD-062
              document.querySelectorAll('[role="form"]').forEach(el => {
                if (visible(el) && !explicitName(el)) issues.push('visible role=form missing accessible name');
              });

              // UX010-HARD-063
              document.querySelectorAll('[role="application"]').forEach(el => {
                if (visible(el) && !explicitName(el)) issues.push('visible role=application missing accessible name');
              });

              // UX010-HARD-064
              document.querySelectorAll('[role="radiogroup"]').forEach(el => {
                if (visible(el) && !explicitName(el)) issues.push('visible role=radiogroup missing accessible name');
              });

              // UX010-HARD-065
              document.querySelectorAll('[role="radio"]').forEach(el => {
                const checked = (el.getAttribute('aria-checked') || '').trim().toLowerCase();
                if (checked !== 'true' && checked !== 'false') issues.push(`role=radio requires boolean aria-checked: ${checked || '(empty)'}`);
              });

              // UX010-HARD-066
              document.querySelectorAll('[role="radiogroup"]').forEach(group => {
                if (!visible(group)) return;
                const radios = [...group.querySelectorAll('[role="radio"]')].filter(el => visible(el) && !disabled(el));
                if (radios.length && !radios.some(el => el.tabIndex >= 0)) issues.push('visible radiogroup has no keyboard entry radio');
              });

              // UX010-HARD-067
              document.querySelectorAll('[role="slider"]').forEach(el => {
                if (visible(el) && !explicitName(el)) issues.push('visible role=slider missing accessible name');
              });

              // UX010-HARD-068
              document.querySelectorAll('[role="slider"]').forEach(el => {
                const raw = (el.getAttribute('aria-valuenow') || '').trim();
                if (!raw || !Number.isFinite(Number(raw))) issues.push(`role=slider requires numeric aria-valuenow: ${raw || '(empty)'}`);
              });

              // UX010-HARD-069
              document.querySelectorAll('[role="treeitem"]').forEach(el => {
                if (visible(el) && !contentName(el)) issues.push('visible role=treeitem missing accessible name');
              });

              // UX010-HARD-070
              document.querySelectorAll('[role="treeitem"]').forEach(el => {
                const ownsGroup = [...el.children].some(child => child.getAttribute('role') === 'group');
                if (!ownsGroup) return;
                const expanded = (el.getAttribute('aria-expanded') || '').trim().toLowerCase();
                if (expanded !== 'true' && expanded !== 'false') issues.push(`treeitem with child group requires boolean aria-expanded: ${expanded || '(empty)'}`);
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
