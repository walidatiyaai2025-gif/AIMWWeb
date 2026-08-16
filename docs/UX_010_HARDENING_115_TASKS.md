# UX-010 — Accessibility Hardening Batch 12 (Tasks 111–115)

This focused five-task slice complements batch 11 by validating the required-owned-element side of composite ARIA relationships. The checks honor explicit `aria-owns` relationships and suppress missing-owned-element findings while the widget or a containing element is marked `aria-busy="true"` during a transient update.

## Completed tasks

- [x] UX010-HARD-111 Require every visible, non-busy `role=tablist` to own at least one `role=tab`.
- [x] UX010-HARD-112 Require every visible, non-busy `role=listbox` to own at least one `role=option`, including options reached through an owned subtree.
- [x] UX010-HARD-113 Require every visible, non-busy `role=menu` or `role=menubar` to own at least one menu item role (`menuitem`, `menuitemcheckbox`, or `menuitemradio`).
- [x] UX010-HARD-114 Require every visible, non-busy `role=tree` to own at least one `role=treeitem`.
- [x] UX010-HARD-115 Require every visible, non-busy ARIA `grid`, `treegrid`, or `table` to own at least one row, accepting explicit `role=row` and native `tr` semantics.

## Execution coverage

The browser suite runs these checks across every public and authenticated route in the shared UX route catalog after the normal UX audit preparation step.

## Standards behavior preserved

The audit recognizes both DOM descendants and elements referenced through `aria-owns`. Missing required owned elements are not reported while the target widget, or one of its containing elements, is explicitly marked `aria-busy="true"`, allowing transient scripted updates to complete before the accessibility contract is evaluated.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding eleven batches, `UX010-HARD-001` through `UX010-HARD-115` are now represented in isolated stacked hardening slices above the UX-010 base regression-gate work.
