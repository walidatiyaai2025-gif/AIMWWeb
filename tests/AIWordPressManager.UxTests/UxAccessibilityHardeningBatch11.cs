using Microsoft.Playwright;

namespace AIWordPressManager.UxTests;

public static class UxAccessibilityHardeningBatch11
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
              const hasAncestorRole = (el, roles) => {
                for (let current = el.parentElement; current; current = current.parentElement) {
                  if (roles.has(roleOf(current))) return true;
                }
                return false;
              };
              const isAriaOwnedByRole = (el, roles) => {
                const id = (el.getAttribute('id') || '').trim();
                if (!id) return false;
                return [...document.querySelectorAll('[aria-owns]')].some(owner => {
                  if (!roles.has(roleOf(owner))) return false;
                  const ownedIds = (owner.getAttribute('aria-owns') || '').trim().split(/\s+/).filter(Boolean);
                  return ownedIds.includes(id);
                });
              };
              const isVisibleInteractive = el => visible(el) && (
                el.matches('a[href],button,input:not([type="hidden"]),select,textarea,summary') ||
                new Set(['button','link','checkbox','radio','switch','tab','menuitem','menuitemcheckbox','menuitemradio','option','combobox','slider','spinbutton']).has(roleOf(el)) ||
                el.tabIndex >= 0 ||
                ['', 'true', 'plaintext-only'].includes((el.getAttribute('contenteditable') || '').trim().toLowerCase()) && el.hasAttribute('contenteditable')
              );

              // UX010-HARD-101
              const seenIds = new Set();
              document.querySelectorAll('[id]').forEach(el => {
                const id = (el.getAttribute('id') || '').trim();
                if (!id) return;
                if (seenIds.has(id)) issues.push(`duplicate id attribute value: ${id}`);
                else seenIds.add(id);
              });

              // UX010-HARD-102
              document.querySelectorAll('[tabindex]').forEach(el => {
                if (!visible(el)) return;
                const raw = (el.getAttribute('tabindex') || '').trim();
                if (/^[+-]?\d+$/.test(raw) && Number(raw) > 0)
                  issues.push(`visible element uses positive tabindex: ${raw}`);
              });

              // UX010-HARD-103
              document.querySelectorAll('button').forEach(button => {
                if (!visible(button)) return;
                const nested = [...button.querySelectorAll('*')].find(isVisibleInteractive);
                if (nested) issues.push(`button contains nested interactive content: ${nested.tagName.toLowerCase()}`);
              });

              // UX010-HARD-104
              document.querySelectorAll('a[href]').forEach(link => {
                if (!visible(link)) return;
                const nested = [...link.querySelectorAll('*')].find(el => isVisibleInteractive(el) && el !== link);
                if (nested) issues.push(`link contains nested interactive content: ${nested.tagName.toLowerCase()}`);
              });

              // UX010-HARD-105
              document.querySelectorAll('[role="tab"]').forEach(el => {
                if (!visible(el)) return;
                const owners = new Set(['tablist']);
                if (!hasAncestorRole(el, owners) && !isAriaOwnedByRole(el, owners))
                  issues.push('visible role=tab is not owned by a tablist');
              });

              // UX010-HARD-106
              document.querySelectorAll('[role="option"]').forEach(el => {
                if (!visible(el)) return;
                const owners = new Set(['listbox']);
                if (!hasAncestorRole(el, owners) && !isAriaOwnedByRole(el, owners))
                  issues.push('visible role=option is not owned by a listbox');
              });

              // UX010-HARD-107
              document.querySelectorAll('[role="menuitem"],[role="menuitemcheckbox"],[role="menuitemradio"]').forEach(el => {
                if (!visible(el)) return;
                const owners = new Set(['menu','menubar']);
                if (!hasAncestorRole(el, owners) && !isAriaOwnedByRole(el, owners))
                  issues.push(`visible role=${roleOf(el)} is not owned by a menu or menubar`);
              });

              // UX010-HARD-108
              document.querySelectorAll('[role="treeitem"]').forEach(el => {
                if (!visible(el)) return;
                const owners = new Set(['tree']);
                if (!hasAncestorRole(el, owners) && !isAriaOwnedByRole(el, owners))
                  issues.push('visible role=treeitem is not owned by a tree');
              });

              // UX010-HARD-109
              document.querySelectorAll('[role="row"]').forEach(el => {
                if (!visible(el)) return;
                const owners = new Set(['table','grid','treegrid','rowgroup']);
                const nativeOwner = el.closest('table,thead,tbody,tfoot');
                if (!nativeOwner && !hasAncestorRole(el, owners) && !isAriaOwnedByRole(el, owners))
                  issues.push('visible role=row is not owned by a table, grid, treegrid, or rowgroup');
              });

              // UX010-HARD-110
              document.querySelectorAll('[role="gridcell"],[role="rowheader"],[role="columnheader"]').forEach(el => {
                if (!visible(el)) return;
                const owners = new Set(['row']);
                const nativeRow = el.closest('tr');
                if (!nativeRow && !hasAncestorRole(el, owners) && !isAriaOwnedByRole(el, owners))
                  issues.push(`visible role=${roleOf(el)} is not owned by a row`);
              });

              return [...new Set(issues)];
            }
            """);

        return issues;
    }
}
