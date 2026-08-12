using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch4
{
    public static async Task<IReadOnlyList<string>> IssuesAsync(IPage page)
    {
        var issues = await page.EvaluateAsync<string[]>("""
            () => {
              const issues = [];
              const validateBoolean = (attribute, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const value = (el.getAttribute(attribute) || '').trim().toLowerCase();
                  if (value !== 'true' && value !== 'false') issues.push(`${message}: ${value || '(empty)'}`);
                });
              };
              const requireNonEmpty = (attribute, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const value = (el.getAttribute(attribute) || '').trim();
                  if (!value) issues.push(message);
                });
              };

              // UX010-HARD-031
              validateBoolean('aria-hidden', 'invalid aria-hidden value');

              // UX010-HARD-032
              validateBoolean('aria-atomic', 'invalid aria-atomic value');

              // UX010-HARD-033
              document.querySelectorAll('[aria-invalid]').forEach(el => {
                const value = (el.getAttribute('aria-invalid') || '').trim().toLowerCase();
                if (!new Set(['false', 'true', 'grammar', 'spelling']).has(value))
                  issues.push(`invalid aria-invalid value: ${value || '(empty)'}`);
              });

              // UX010-HARD-034
              document.querySelectorAll('[aria-relevant]').forEach(el => {
                const raw = (el.getAttribute('aria-relevant') || '').trim().toLowerCase();
                const tokens = raw.split(/\s+/).filter(Boolean);
                const allowed = new Set(['additions', 'removals', 'text', 'all']);
                if (!tokens.length) {
                  issues.push('invalid aria-relevant value: (empty)');
                  return;
                }
                tokens.forEach(token => {
                  if (!allowed.has(token)) issues.push(`invalid aria-relevant token: ${token}`);
                });
              });

              // UX010-HARD-035
              requireNonEmpty('aria-valuetext', 'aria-valuetext must not be empty');

              // UX010-HARD-036
              requireNonEmpty('aria-roledescription', 'aria-roledescription must not be empty');

              // UX010-HARD-037
              requireNonEmpty('aria-description', 'aria-description must not be empty');

              // UX010-HARD-038
              requireNonEmpty('aria-placeholder', 'aria-placeholder must not be empty');

              // UX010-HARD-039
              requireNonEmpty('aria-keyshortcuts', 'aria-keyshortcuts must not be empty');

              // UX010-HARD-040
              requireNonEmpty('aria-label', 'aria-label must not be empty when present');

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
