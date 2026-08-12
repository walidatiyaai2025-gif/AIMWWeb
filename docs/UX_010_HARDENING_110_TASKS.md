# UX-010 — Accessibility Hardening Batch 11 (Tasks 101–110)

This batch adds ten test-only guards for DOM identity, keyboard focus order, nested interactive controls, and structural ownership of composite ARIA widgets.

## Completed tasks

- [x] UX010-HARD-101 Reject duplicate non-empty `id` attribute values in the rendered DOM.
- [x] UX010-HARD-102 Reject visible elements that use a positive `tabindex`, preserving document-order keyboard navigation.
- [x] UX010-HARD-103 Reject visible interactive descendants nested inside native `button` elements.
- [x] UX010-HARD-104 Reject visible interactive descendants nested inside links with `href`.
- [x] UX010-HARD-105 Require visible `role=tab` elements to be owned by a `role=tablist` container or explicit `aria-owns` relationship.
- [x] UX010-HARD-106 Require visible `role=option` elements to be owned by a `role=listbox` container or explicit `aria-owns` relationship.
- [x] UX010-HARD-107 Require visible menu item roles to be owned by `role=menu` or `role=menubar`.
- [x] UX010-HARD-108 Require visible `role=treeitem` elements to be owned by `role=tree`.
- [x] UX010-HARD-109 Require visible `role=row` elements to be structurally owned by table/grid/treegrid/rowgroup semantics.
- [x] UX010-HARD-110 Require visible ARIA grid/header cells to be structurally owned by a row.

## Execution coverage

The browser suite runs these checks across every public and authenticated route in the shared UX route catalog after the normal UX audit preparation step.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding ten batches, `UX010-HARD-001` through `UX010-HARD-110` are now represented as eleven isolated 10-task hardening slices stacked above the UX-010 base regression-gate work.
