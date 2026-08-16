using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch16
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

              // UX010-HARD-131
              document.querySelectorAll('[role="columnheader"]').forEach(el => {
                if (visible(el) && !contentAccessibleName(el))
                  issues.push('visible role=columnheader missing accessible name');
              });

              // UX010-HARD-132
              document.querySelectorAll('[role="rowheader"]').forEach(el => {
                if (visible(el) && !contentAccessibleName(el))
                  issues.push('visible role=rowheader missing accessible name');
              });

              // UX010-HARD-133
              document.querySelectorAll('[role="tabpanel"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=tabpanel missing accessible name');
              });

              // UX010-HARD-134
              document.querySelectorAll('[role="tooltip"]').forEach(el => {
                if (visible(el) && !contentAccessibleName(el))
                  issues.push('visible role=tooltip missing accessible name');
              });

              // UX010-HARD-135
              document.querySelectorAll('[role="table"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=table missing accessible name');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
