# UX-010 — Accessibility Hardening Batch 14 (Tasks 121–125)

This focused five-task slice closes remaining required-accessible-name gaps for ARIA input, range, selection, and tree widgets. The checks use author-provided naming sources instead of treating widget value/content as an implicit label where the role requires an author name.

## Completed tasks

- [x] UX010-HARD-121 Require every visible `role=spinbutton` to expose an accessible name.
- [x] UX010-HARD-122 Require every visible `role=textbox` to expose an accessible name.
- [x] UX010-HARD-123 Require every visible `role=progressbar` to expose an accessible name.
- [x] UX010-HARD-124 Require every visible `role=listbox` to expose an accessible name.
- [x] UX010-HARD-125 Require every visible `role=tree` to expose an accessible name.

## Naming sources

The audit recognizes explicit `aria-label`, resolving `aria-labelledby` references, native label associations where supported, `title`, and input `placeholder` fallback. It deliberately does not use arbitrary widget text/value content as the accessible name for these author-named roles.

## Execution coverage

The browser suite runs these checks across every public and authenticated route in the shared UX route catalog after the normal UX audit preparation step.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding batches, `UX010-HARD-001` through `UX010-HARD-125` are now represented in isolated stacked hardening slices above the UX-010 base regression-gate work.
