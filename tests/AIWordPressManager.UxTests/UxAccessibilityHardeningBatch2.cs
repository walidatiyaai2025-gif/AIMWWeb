using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch2
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
              const validateEnumerated = (attribute, allowed, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  const value = (el.getAttribute(attribute) || '').trim().toLowerCase();
                  if (!allowed.has(value)) issues.push(`${message}: ${value || '(empty)'}`);
                });
              };

              // UX010-HARD-011
              validateEnumerated(
                'aria-current',
                new Set(['page', 'step', 'location', 'date', 'time', 'true', 'false']),
                'invalid aria-current value');

              // UX010-HARD-012
              validateEnumerated(
                'aria-haspopup',
                new Set(['false', 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog']),
                'invalid aria-haspopup value');

              // UX010-HARD-013
              validateEnumerated(
                'aria-live',
                new Set(['off', 'polite', 'assertive']),
                'invalid aria-live value');

              // UX010-HARD-014
              validateEnumerated(
                'aria-orientation',
                new Set(['horizontal', 'vertical']),
                'invalid aria-orientation value');

              // UX010-HARD-015
              validateEnumerated(
                'aria-sort',
                new Set(['none', 'ascending', 'descending', 'other']),
                'invalid aria-sort value');

              // UX010-HARD-016
              validateEnumerated(
                'aria-autocomplete',
                new Set(['none', 'inline', 'list', 'both']),
                'invalid aria-autocomplete value');

              // UX010-HARD-017
              document.querySelectorAll('[role="heading"]').forEach(el => {
                if (!visible(el)) return;
                const raw = (el.getAttribute('aria-level') || '').trim();
                const level = Number(raw);
                if (!raw || !Number.isInteger(level) || level < 1 || level > 6)
                  issues.push(`role=heading has invalid aria-level: ${raw || '(empty)'}`);
              });

              // UX010-HARD-018
              document.querySelectorAll('input[type="image"]').forEach(el => {
                if (!visible(el)) return;
                if (!(el.getAttribute('alt') || '').trim()) issues.push('input[type=image] missing alt text');
              });

              // UX010-HARD-019
              validateEnumerated(
                'aria-disabled',
                new Set(['true', 'false']),
                'invalid aria-disabled value');

              // UX010-HARD-020
              document.querySelectorAll('[aria-valuemin],[aria-valuemax],[aria-valuenow]').forEach(el => {
                const rawMin = el.getAttribute('aria-valuemin');
                const rawMax = el.getAttribute('aria-valuemax');
                const rawNow = el.getAttribute('aria-valuenow');
                const parse = (raw, name) => {
                  if (raw === null) return null;
                  const value = Number(raw.trim());
                  if (!raw.trim() || !Number.isFinite(value)) {
                    issues.push(`${name} must be numeric: ${raw || '(empty)'}`);
                    return Number.NaN;
                  }
                  return value;
                };
                const min = parse(rawMin, 'aria-valuemin');
                const max = parse(rawMax, 'aria-valuemax');
                const now = parse(rawNow, 'aria-valuenow');
                if (Number.isFinite(min) && Number.isFinite(max) && min > max)
                  issues.push('aria-valuemin must not exceed aria-valuemax');
                if (Number.isFinite(now) && Number.isFinite(min) && now < min)
                  issues.push('aria-valuenow is below aria-valuemin');
                if (Number.isFinite(now) && Number.isFinite(max) && now > max)
                  issues.push('aria-valuenow exceeds aria-valuemax');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
