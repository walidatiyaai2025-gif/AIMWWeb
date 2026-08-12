using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch9
{
    public static async Task<IReadOnlyList<string>> IssuesAsync(IPage page)
    {
        var issues = await page.EvaluateAsync<string[]>("""
            () => {
              const issues = [];
              const requireNonEmpty = (attribute, message) => {
                document.querySelectorAll(`[${attribute}]`).forEach(el => {
                  if (!(el.getAttribute(attribute) || '').trim()) issues.push(message);
                });
              };

              // UX010-HARD-081
              document.querySelectorAll('[aria-flowto]').forEach(el => {
                const ids = (el.getAttribute('aria-flowto') || '').trim().split(/\s+/).filter(Boolean);
                if (!ids.length) issues.push('aria-flowto must reference at least one id');
                [...new Set(ids)].forEach(id => {
                  if (!document.getElementById(id)) issues.push(`aria-flowto references missing id: ${id}`);
                });
              });

              // UX010-HARD-082
              document.querySelectorAll('[aria-posinset]').forEach(el => {
                const raw = (el.getAttribute('aria-posinset') || '').trim();
                const value = Number(raw);
                if (!raw || !Number.isInteger(value) || value < 1)
                  issues.push(`aria-posinset must be a positive integer: ${raw || '(empty)'}`);
              });

              // UX010-HARD-083
              document.querySelectorAll('[aria-setsize]').forEach(el => {
                const raw = (el.getAttribute('aria-setsize') || '').trim();
                const value = Number(raw);
                if (!raw || !Number.isInteger(value) || (value !== -1 && value < 1))
                  issues.push(`aria-setsize must be -1 or a positive integer: ${raw || '(empty)'}`);
              });

              // UX010-HARD-084
              document.querySelectorAll('[aria-posinset][aria-setsize]').forEach(el => {
                const pos = Number((el.getAttribute('aria-posinset') || '').trim());
                const size = Number((el.getAttribute('aria-setsize') || '').trim());
                if (Number.isInteger(pos) && Number.isInteger(size) && size > 0 && pos > size)
                  issues.push(`aria-posinset exceeds aria-setsize: ${pos} > ${size}`);
              });

              // UX010-HARD-085
              document.querySelectorAll('[aria-level]').forEach(el => {
                const raw = (el.getAttribute('aria-level') || '').trim();
                const value = Number(raw);
                if (!raw || !Number.isInteger(value) || value < 1)
                  issues.push(`aria-level must be a positive integer: ${raw || '(empty)'}`);
              });

              // UX010-HARD-086
              requireNonEmpty('aria-colindextext', 'aria-colindextext must not be empty');

              // UX010-HARD-087
              requireNonEmpty('aria-rowindextext', 'aria-rowindextext must not be empty');

              // UX010-HARD-088
              requireNonEmpty('aria-braillelabel', 'aria-braillelabel must not be empty');

              // UX010-HARD-089
              requireNonEmpty('aria-brailleroledescription', 'aria-brailleroledescription must not be empty');

              // UX010-HARD-090
              document.querySelectorAll('[aria-dropeffect],[aria-grabbed]').forEach(el => {
                if (el.hasAttribute('aria-dropeffect')) issues.push('deprecated aria-dropeffect must not be used');
                if (el.hasAttribute('aria-grabbed')) issues.push('deprecated aria-grabbed must not be used');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
