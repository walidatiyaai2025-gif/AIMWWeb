# UX-010 — Accessibility Hardening Batch 17 (Tasks 136–140)

This focused five-task slice continues the WAI-ARIA 1.2 hardening work with required heading naming, required checked state for checkable menu items, and required combobox popup relationships.

## Completed tasks

- [x] UX010-HARD-136 Require every visible `role=heading` to expose an accessible name from author labeling or permitted contents.
- [x] UX010-HARD-137 Require every visible `role=menuitemcheckbox` to expose explicit `aria-checked` as `true`, `false`, or `mixed`.
- [x] UX010-HARD-138 Require every visible `role=menuitemradio` to expose explicit boolean `aria-checked` as `true` or `false`.
- [x] UX010-HARD-139 Require every visible `role=combobox` to expose resolving `aria-controls` that identifies a popup with role `listbox`, `tree`, `grid`, or `dialog`.
- [x] UX010-HARD-140 Require a combobox's `aria-haspopup` value to match the controlled non-listbox popup role; the implicit/default listbox case remains valid.

## Execution coverage

The browser suite executes this audit across every public and authenticated route in the shared UX route catalog after the standard UX audit preparation step.

## Semantics

WAI-ARIA 1.2 requires an accessible name for `heading`, requires `aria-checked` for `menuitemcheckbox` and `menuitemradio`, and requires `combobox` to reference its popup using `aria-controls`. Popup roles are constrained to listbox, tree, grid, or dialog; non-listbox popup roles require a corresponding explicit `aria-haspopup` value.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding sixteen batches, `UX010-HARD-001` through `UX010-HARD-140` are represented in isolated stacked hardening slices above the UX-010 base regression-gate work.
