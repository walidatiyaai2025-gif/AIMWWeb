using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch13
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
              const labelText = el => el.labels
                ? [...el.labels].map(label => (label.innerText || label.textContent || '').trim()).filter(Boolean).join(' ')
                : '';
              const authorName = el => (
                el.getAttribute('aria-label') ||
                referenceText(el, 'aria-labelledby') ||
                labelText(el) ||
                el.getAttribute('title') ||
                ''
              ).trim();
              const contentName = el => (authorName(el) || el.innerText || el.textContent || '').trim();
              const numericAttribute = (el, name) => {
                const raw = (el.getAttribute(name) || '').trim();
                return raw !== '' && Number.isFinite(Number(raw));
              };
              const idsResolve = (el, name) => {
                const ids = (el.getAttribute(name) || '').trim().split(/\s+/).filter(Boolean);
                return ids.length > 0 && ids.every(id => document.getElementById(id));
              };

              // UX010-HARD-116
              document.querySelectorAll('[role="checkbox"]').forEach(el => {
                if (!visible(el)) return;
                if (!contentName(el)) issues.push('visible role=checkbox missing accessible name');
                const checked = (el.getAttribute('aria-checked') || '').trim().toLowerCase();
                if (!['true', 'false', 'mixed'].includes(checked))
                  issues.push(`role=checkbox requires aria-checked true, false, or mixed: ${checked || '(empty)'}`);
              });

              // UX010-HARD-117
              document.querySelectorAll('[role="meter"]').forEach(el => {
                if (!visible(el)) return;
                if (!authorName(el)) issues.push('visible role=meter missing accessible name');
                if (!numericAttribute(el, 'aria-valuenow'))
                  issues.push('visible role=meter requires numeric aria-valuenow');
              });

              // UX010-HARD-118
              document.querySelectorAll('[role="scrollbar"]').forEach(el => {
                if (!visible(el)) return;
                if (!idsResolve(el, 'aria-controls'))
                  issues.push('visible role=scrollbar requires resolving aria-controls');
                if (!numericAttribute(el, 'aria-valuenow'))
                  issues.push('visible role=scrollbar requires numeric aria-valuenow');
              });

              // UX010-HARD-119
              document.querySelectorAll('[role="separator"]').forEach(el => {
                if (!visible(el) || el.tabIndex < 0) return;
                if (!numericAttribute(el, 'aria-valuenow'))
                  issues.push('focusable role=separator requires numeric aria-valuenow');
              });

              // UX010-HARD-120
              document.querySelectorAll('[role="searchbox"]').forEach(el => {
                if (visible(el) && !authorName(el))
                  issues.push('visible role=searchbox missing accessible name');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
