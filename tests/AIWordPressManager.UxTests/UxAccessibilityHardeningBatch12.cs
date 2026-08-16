using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch12
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
              const roleOf = el => (el.getAttribute('role') || '').trim().toLowerCase();
              const isBusy = el => el.closest('[aria-busy="true"]') !== null;
              const ariaOwnedRoots = owner => {
                const ids = (owner.getAttribute('aria-owns') || '').trim().split(/\s+/).filter(Boolean);
                return ids.map(id => document.getElementById(id)).filter(Boolean);
              };
              const ownsMatching = (owner, predicate) => {
                if ([...owner.querySelectorAll('*')].some(predicate)) return true;
                return ariaOwnedRoots(owner).some(root => predicate(root) || [...root.querySelectorAll('*')].some(predicate));
              };
              const hasRole = (...roles) => el => roles.includes(roleOf(el));

              // UX010-HARD-111
              document.querySelectorAll('[role="tablist"]').forEach(el => {
                if (!visible(el) || isBusy(el)) return;
                if (!ownsMatching(el, hasRole('tab')))
                  issues.push('visible role=tablist is missing an owned tab');
              });

              // UX010-HARD-112
              document.querySelectorAll('[role="listbox"]').forEach(el => {
                if (!visible(el) || isBusy(el)) return;
                if (!ownsMatching(el, hasRole('option')))
                  issues.push('visible role=listbox is missing an owned option');
              });

              // UX010-HARD-113
              document.querySelectorAll('[role="menu"],[role="menubar"]').forEach(el => {
                if (!visible(el) || isBusy(el)) return;
                if (!ownsMatching(el, hasRole('menuitem', 'menuitemcheckbox', 'menuitemradio')))
                  issues.push(`visible role=${roleOf(el)} is missing an owned menu item`);
              });

              // UX010-HARD-114
              document.querySelectorAll('[role="tree"]').forEach(el => {
                if (!visible(el) || isBusy(el)) return;
                if (!ownsMatching(el, hasRole('treeitem')))
                  issues.push('visible role=tree is missing an owned treeitem');
              });

              // UX010-HARD-115
              document.querySelectorAll('[role="grid"],[role="treegrid"],[role="table"]').forEach(el => {
                if (!visible(el) || isBusy(el)) return;
                const ownsRow = ownsMatching(el, candidate => candidate.matches('tr') || roleOf(candidate) === 'row');
                if (!ownsRow)
                  issues.push(`visible role=${roleOf(el)} is missing an owned row`);
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
