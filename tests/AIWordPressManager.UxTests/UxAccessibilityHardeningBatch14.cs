using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch14
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
                el.getAttribute('placeholder') ||
                ''
              ).trim();

              // UX010-HARD-121
              document.querySelectorAll('[role="spinbutton"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=spinbutton missing accessible name');
              });

              // UX010-HARD-122
              document.querySelectorAll('[role="textbox"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=textbox missing accessible name');
              });

              // UX010-HARD-123
              document.querySelectorAll('[role="progressbar"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=progressbar missing accessible name');
              });

              // UX010-HARD-124
              document.querySelectorAll('[role="listbox"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=listbox missing accessible name');
              });

              // UX010-HARD-125
              document.querySelectorAll('[role="tree"]').forEach(el => {
                if (visible(el) && !authorAccessibleName(el))
                  issues.push('visible role=tree missing accessible name');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
