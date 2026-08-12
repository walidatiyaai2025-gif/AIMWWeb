using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch5
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
              const accessibleName = el => (
                el.getAttribute('aria-label') ||
                referenceText(el, 'aria-labelledby') ||
                el.getAttribute('title') ||
                ''
              ).trim();

              // UX010-HARD-041
              document.querySelectorAll('label[for]').forEach(label => {
                const id = (label.getAttribute('for') || '').trim();
                const target = id ? document.getElementById(id) : null;
                if (!target) {
                  issues.push(`label for references missing id: ${id || '(empty)'}`);
                  return;
                }
                if (!target.matches('button,input:not([type="hidden"]),meter,output,progress,select,textarea'))
                  issues.push(`label for target is not labelable: ${id}`);
              });

              // UX010-HARD-042
              document.querySelectorAll('output[for]').forEach(output => {
                const ids = (output.getAttribute('for') || '').trim().split(/\s+/).filter(Boolean);
                if (!ids.length) issues.push('output for must reference at least one id');
                ids.forEach(id => {
                  if (!document.getElementById(id)) issues.push(`output for references missing id: ${id}`);
                });
              });

              // UX010-HARD-043
              document.querySelectorAll('input[list]').forEach(input => {
                const id = (input.getAttribute('list') || '').trim();
                const target = id ? document.getElementById(id) : null;
                if (!target || target.tagName !== 'DATALIST')
                  issues.push(`input list must reference a datalist: ${id || '(empty)'}`);
              });

              // UX010-HARD-044
              document.querySelectorAll('button[form],fieldset[form],input[form],object[form],output[form],select[form],textarea[form]').forEach(control => {
                const id = (control.getAttribute('form') || '').trim();
                const target = id ? document.getElementById(id) : null;
                if (!target || target.tagName !== 'FORM')
                  issues.push(`form-associated control references missing form: ${id || '(empty)'}`);
              });

              // UX010-HARD-045
              document.querySelectorAll('td[headers],th[headers]').forEach(cell => {
                const ids = (cell.getAttribute('headers') || '').trim().split(/\s+/).filter(Boolean);
                if (!ids.length) issues.push('table cell headers must reference at least one th id');
                ids.forEach(id => {
                  const target = document.getElementById(id);
                  if (!target || target.tagName !== 'TH') issues.push(`table cell headers references missing th id: ${id}`);
                });
              });

              // UX010-HARD-046
              document.querySelectorAll('[usemap]').forEach(el => {
                const raw = (el.getAttribute('usemap') || '').trim();
                const name = raw.startsWith('#') ? raw.slice(1) : '';
                const target = name ? [...document.querySelectorAll('map[name]')].find(map => map.getAttribute('name') === name) : null;
                if (!target) issues.push(`usemap references missing map: ${raw || '(empty)'}`);
              });

              // UX010-HARD-047
              document.querySelectorAll('map area[href]').forEach(area => {
                if (!(area.getAttribute('alt') || '').trim()) issues.push('image-map area link missing alt text');
              });

              // UX010-HARD-048
              document.querySelectorAll('details').forEach(details => {
                if (!visible(details)) return;
                if (!details.querySelector(':scope > summary')) issues.push('visible details element missing direct summary');
              });

              // UX010-HARD-049
              const navs = [...document.querySelectorAll('nav')].filter(visible);
              if (navs.length > 1) navs.forEach(nav => {
                if (!accessibleName(nav)) issues.push('multiple visible nav landmarks require accessible names');
              });

              // UX010-HARD-050
              const asides = [...document.querySelectorAll('aside')].filter(visible);
              if (asides.length > 1) asides.forEach(aside => {
                if (!accessibleName(aside)) issues.push('multiple visible aside landmarks require accessible names');
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
