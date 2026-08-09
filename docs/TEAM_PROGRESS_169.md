# AIMWWeb Team Progress - 169

## SITE-BULK-015 Multi-site selection and bulk actions

Status: Implemented - CI validation pending

## Scope
Phase 3 still had one confirmed site-management gap: `/sites` supported only per-site actions even though the shared UI framework already included a bulk action bar.

## Implementation
- Added multi-select checkboxes to site profile cards.
- Added select/clear-visible behavior that respects search and status filters.
- Selection is pruned when filtering hides a site so actions cannot silently affect hidden profiles.
- Reused `AppBulkActionBar` for a consistent application-wide bulk-action pattern.
- Added bulk actions:
  - Retest selected connections.
  - Enable selected site profiles.
  - Disable selected site profiles.
  - Delete selected profiles with explicit confirmation.
- Added dedicated selected-card, checkbox, responsive, and reduced-motion styling.
- Bulk operations are limited to 100 unique non-empty site IDs per request.

## Tenancy and safety
- Backend normalizes and validates the complete selected ID set before mutation.
- Every selected site must belong to the current signed-in owner before disable/delete changes are applied.
- A foreign or missing site ID aborts the validated bulk mutation rather than partially modifying owned sites.
- Bulk retest performs the same complete ownership pre-check, then reports per-site connection success/failure without masking other results.
- Bulk delete preserves the existing soft-delete behavior and requires confirmation in the UI.
- Bulk edit was intentionally not added because changing names/URLs across multiple independent profiles is ambiguous and riskier than explicit per-profile editing.

## Regression coverage
- ID normalization removes empty and duplicate IDs while preserving order.
- Empty selections and selections above the 100-site limit are rejected.
- Visible-selection state requires every visible profile to be selected.
- Mixed owned/foreign selections fail before any disable mutation occurs.
- Valid owned bulk deletion soft-deletes all selected profiles.

## Release
- Web version: `155.116.0`.

## Validation gate
1. Full solution build including Razor compilation.
2. Full automated test suite including bulk policy/ownership tests.
3. Build workflow green.
4. .NET Build Verification green.
5. Merge only after both gates pass.
