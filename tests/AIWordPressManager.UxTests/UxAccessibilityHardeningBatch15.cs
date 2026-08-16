using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch15
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
              const labelText = el => el.labels
                ? [...el.labels].map(label => (label.innerText || label.textContent || '').trim()).filter(Boolean).join(' ')
                : '';
              const authorAccessibleName = el => (
                el.getAttribute('aria-label') ||
                referencedText(el) ||
                labelText(el) ||
                el.getAttribute('title') ||
                ''
              ).trim();
              const contentAccessibleName = el => (
                authorAccessibleName(el) ||
                el.innerText ||
                el.textContent ||
                ''
              ).trim();

              // UX010-HARD-126
              document.querySelectorAll('[role="button"]').forEach(el => {
                if (visible(el) && !contentAccessibleName(el))
                  issues.push('visible role=button missing accessible name');
              });

              // UX010-HARD-127
              document.querySelectorAll('[role="radio"]').forEach(el => {
                if (visible(el) && !contentAccessibleName(el))
                  issues.push('visible role=radio missing accessible name');
              });

              // UX010-HARD-128
              document.querySelectorAll('[role="switch"]').forEach(el => {
                if (visible(el) && !contentAccessibleName(el))
                  issues.push('visible role=switch missing accessible name');
              });

              // UX010-HARD-129
              document.querySelectorAll('[role="grid"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=grid missing accessible name');
              });

              // UX010-HARD-130
              document.querySelectorAll('[role="treegrid"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=treegrid missing accessible name');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
