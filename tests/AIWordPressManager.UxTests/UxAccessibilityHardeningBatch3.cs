using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch3
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
              const validateIdRefs = (attribute, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const raw = (el.getAttribute(attribute) || '').trim();
                  if (!raw) {
                    issues.push(`${message}: (empty)`);
                    return;
                  }
                  [...new Set(raw.split(/\s+/).filter(Boolean))].forEach(id => {
                    if (!document.getElementById(id)) issues.push(`${message}: ${id}`);
                  });
                });
              };
              const validatePositiveInteger = (attribute, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const raw = (el.getAttribute(attribute) || '').trim();
                  const value = Number(raw);
                  if (!raw || !Number.isInteger(value) || value < 1)
                    issues.push(`${message}: ${raw || '(empty)'}`);
                });
              };
              const validateCount = (attribute, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const raw = (el.getAttribute(attribute) || '').trim();
                  const value = Number(raw);
                  if (!raw || !Number.isInteger(value) || (value !== -1 && value < 1))
                    issues.push(`${message}: ${raw || '(empty)'}`);
                });
              };

              // UX010-HARD-021
              validateBoolean('aria-busy', 'invalid aria-busy value');

              // UX010-HARD-022
              validateBoolean('aria-multiline', 'invalid aria-multiline value');

              // UX010-HARD-023
              validateBoolean('aria-multiselectable', 'invalid aria-multiselectable value');

              // UX010-HARD-024
              validateBoolean('aria-readonly', 'invalid aria-readonly value');

              // UX010-HARD-025
              validateBoolean('aria-required', 'invalid aria-required value');

              // UX010-HARD-026
              validateBoolean('aria-modal', 'invalid aria-modal value');

              // UX010-HARD-027
              validateIdRefs('aria-errormessage', 'aria-errormessage references missing id');

              // UX010-HARD-028
              validateIdRefs('aria-details', 'aria-details references missing id');

              // UX010-HARD-029
              document.querySelectorAll('[aria-activedescendant]').forEach(el => {
                const raw = (el.getAttribute('aria-activedescendant') || '').trim();
                const ids = raw.split(/\s+/).filter(Boolean);
                if (ids.length !== 1) {
                  issues.push(`aria-activedescendant must reference exactly one id: ${raw || '(empty)'}`);
                  return;
                }
                if (!document.getElementById(ids[0]))
                  issues.push(`aria-activedescendant references missing id: ${ids[0]}`);
              });

              // UX010-HARD-030
              validatePositiveInteger('aria-colindex', 'aria-colindex must be a positive integer');
              validatePositiveInteger('aria-rowindex', 'aria-rowindex must be a positive integer');
              validatePositiveInteger('aria-colspan', 'aria-colspan must be a positive integer');
              validatePositiveInteger('aria-rowspan', 'aria-rowspan must be a positive integer');
              validateCount('aria-colcount', 'aria-colcount must be -1 or a positive integer');
              validateCount('aria-rowcount', 'aria-rowcount must be -1 or a positive integer');

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
