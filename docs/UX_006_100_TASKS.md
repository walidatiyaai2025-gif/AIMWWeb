# UX-006 — 100 Data Table, Filter, Bulk Action & Dense Workspace Tasks

Issue: #51  
Branch: `agent/ux-006-dense-data-workspaces`  
Scope: shared dense-data hierarchy, search/filter state, sorting, pagination, selection scope, bulk actions, responsive alternatives, RTL/LTR parity, keyboard scanability, and production adoption without changing domain/service contracts.

## Shared grid hierarchy and semantics

- [x] 01. Keep one reusable `AppDataGrid` contract for operational tables.
- [x] 02. Add explicit density metadata to the grid root.
- [x] 03. Add compact density support for high-volume workspaces.
- [x] 04. Add spacious density support without page-local table CSS.
- [x] 05. Add configurable sticky-header behavior.
- [x] 06. Add optional striped-row scanability.
- [x] 07. Keep a stable accessible table label.
- [x] 08. Add optional semantic table caption text.
- [x] 09. Associate the table with the live result summary.
- [x] 10. Expose filtered row count through `aria-rowcount`.
- [x] 11. Make the horizontal viewport keyboard focusable.
- [x] 12. Give the scrollable viewport an accessible name.
- [x] 13. Add a visible keyboard focus treatment to the viewport.
- [x] 14. Keep header cells compatible with existing consumer-provided `scope=col`.
- [x] 15. Preserve source compatibility for existing required grid parameters.

## Search and filter state

- [x] 16. Keep shared search inside the data-grid command bar.
- [x] 17. Add a dedicated filter fragment beside search.
- [x] 18. Give filter controls a grouped accessible label.
- [x] 19. Add an external `FilterPredicate` contract for page-owned filters.
- [x] 20. Combine external filters with grid search without duplicating item collections.
- [x] 21. Track the external active-filter count.
- [x] 22. Count grid search as an active filter state.
- [x] 23. Add a live active-filter summary row.
- [x] 24. Add an optional consumer-provided filter summary fragment.
- [x] 25. Add clear-all-filters behavior to the command bar.
- [x] 26. Reset the current page when search changes.
- [x] 27. Reset the current page when filters are cleared.
- [x] 28. Notify consumers when the search value changes.
- [x] 29. Distinguish a truly empty dataset from filtered no-results.
- [x] 30. Give no-results state its own localized title, description, and clear action.

## Shared filter controls

- [x] 31. Add reusable `AppFilterBar` rather than page-local filter toolbars.
- [x] 32. Give `AppFilterBar` search-region semantics.
- [x] 33. Expose busy state on shared filter bars.
- [x] 34. Show active-filter count as a live status.
- [x] 35. Support a clear-all action from the filter bar.
- [x] 36. Prevent clear actions while filters are busy.
- [x] 37. Add optional no-filter summary copy.
- [x] 38. Add an applied-filter chip region.
- [x] 39. Add reusable `AppFilterChip`.
- [x] 40. Let filter chips show both label and value.
- [x] 41. Let filter chips expose an accessible remove name.
- [x] 42. Prevent chip removal while disabled.
- [x] 43. Keep chip removal keyboard accessible with a real button.
- [x] 44. Add optional chip tone metadata.
- [x] 45. Keep filter bar/chips logical-property and RTL friendly.

## Selection scope and bulk selection

- [x] 46. Preserve stable key-based row selection.
- [x] 47. Mark selected desktop rows with `aria-selected`.
- [x] 48. Mark selected mobile rows with `aria-selected`.
- [x] 49. Keep per-row accessible selection labels.
- [x] 50. Keep page-level select-visible behavior.
- [x] 51. Add select-all-filtered behavior across pagination.
- [x] 52. Explain selection scope before selecting all filtered rows.
- [x] 53. Add explicit clear-selection action in the command bar.
- [x] 54. Add explicit clear-selection action after all filtered rows are selected.
- [x] 55. Emit selection-changed notifications after item toggle.
- [x] 56. Emit selection-changed notifications after page toggle.
- [x] 57. Emit selection-changed notifications after select-all-filtered.
- [x] 58. Emit selection-changed notifications after clear-selection.
- [x] 59. Reconcile stale selected keys when the backing dataset changes.
- [x] 60. Allow consumers to opt into preserving missing-item selections when required.

## Result, pagination, sorting and export UX

- [x] 61. Keep visible-range summary in a polite live region.
- [x] 62. Report hidden selected rows outside the current filter.
- [x] 63. Keep page-size controls labelled.
- [x] 64. Remove invalid/non-positive page-size options.
- [x] 65. Remove duplicate page-size options.
- [x] 66. Sort page-size options for predictable scanning.
- [x] 67. Clamp page index when filtered results shrink.
- [x] 68. Keep previous-page disabled state at the first page.
- [x] 69. Keep next-page disabled state at the final page.
- [x] 70. Preserve localized pagination labels.
- [x] 71. Add explicit current sort-direction wording.
- [x] 72. Add sort direction to button title and accessible name.
- [x] 73. Emit a sort-direction callback to consumers.
- [x] 74. Reset pagination when sort direction changes.
- [x] 75. Keep CSV export scoped to the filtered/sorted result set.

## Dense row states, responsive and accessibility resilience

- [x] 76. Add consumer-defined row class selection.
- [x] 77. Add consumer-defined row-state metadata.
- [x] 78. Add non-color row-state edge indicators.
- [x] 79. Add optional focusable rows for dense keyboard review workflows.
- [x] 80. Add visible row keyboard focus styling.
- [x] 81. Preserve non-color selected-row indication in LTR.
- [x] 82. Mirror selected-row indication in RTL.
- [x] 83. Preserve mobile card alternatives when provided.
- [x] 84. Keep mobile selected cards non-color identifiable.
- [x] 85. Stack command-bar actions safely on narrow screens.
- [x] 86. Stack selection-scope messaging safely on narrow screens.
- [x] 87. Add reduced-motion behavior for dense-row transitions.
- [x] 88. Add forced-colors treatment for selected rows and row states.
- [x] 89. Keep touch-sized filter controls at 44px minimum height.
- [x] 90. Preserve safe bounded horizontal scrolling when no mobile template exists.

## Bulk actions and production adoption

- [x] 91. Harden `AppBulkActionBar` with an explicit accessible region label.
- [x] 92. Expose bulk busy state through `aria-busy` and a live busy message.
- [x] 93. Add bulk selection scope/recovery copy without page-local markup.
- [x] 94. Keep sticky bulk actions above device safe-area bottom insets.
- [x] 95. Add dangerous bulk-action visual semantics without color-only meaning.
- [x] 96. Add optional secondary bulk actions and a labelled clear-selection action.
- [x] 97. Replace AI Usage's page-local site filter toolbar with `AppFilterBar` and `AppFilterChip`.
- [x] 98. Replace AI Usage's manual recent-calls table with shared `AppDataGrid`, CSV export, search, compact density, row states, and mobile cards.
- [x] 99. Add deterministic dense-workspace contract tests covering grid, filter bar/chips, bulk bar, AI Usage adoption, CSS, and the exact task manifest.
- [x] 100. Record the UX-006 compatibility boundary: presentation/query-state UX only; no database, auth, tenant, AI runtime, or persistence service contract changes.

## Compatibility boundary

UX-006 changes shared presentation, client-side query state, filtering/search affordances, selection visibility, export presentation, responsive behavior, and bulk-action interaction UX. Existing services remain authoritative. No database schema, tenant ownership, authentication model, API contract, AI runtime routing, usage-log persistence contract, or WordPress execution contract is intentionally changed.
