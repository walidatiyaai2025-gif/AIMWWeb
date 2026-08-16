# UX-010 — Accessibility Hardening Batch 16 (Tasks 131–135)

This focused five-task slice continues the WAI-ARIA 1.2 accessible-name hardening work with required naming semantics not represented in the preceding 130-task stack.

## Completed tasks

- [x] UX010-HARD-131 Require every visible `role=columnheader` to expose an accessible name from author-provided labeling or permitted contents.
- [x] UX010-HARD-132 Require every visible `role=rowheader` to expose an accessible name from author-provided labeling or permitted contents.
- [x] UX010-HARD-133 Require every visible `role=tabpanel` to expose an author-provided accessible name.
- [x] UX010-HARD-134 Require every visible `role=tooltip` to expose an accessible name from author-provided labeling or permitted contents.
- [x] UX010-HARD-135 Require every visible `role=table` to expose an author-provided accessible name.

## Execution coverage

The browser suite runs these checks across every public and authenticated route in the shared UX route catalog after the standard UX audit preparation step.

## Naming semantics

Column headers, row headers, and tooltips accept supported author labeling or role-permitted textual contents. Tabpanels and ARIA tables intentionally require author-provided naming sources so arbitrary descendant content is not treated as the widget label.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding fifteen batches, `UX010-HARD-001` through `UX010-HARD-135` are represented in isolated stacked hardening slices above the UX-010 base regression-gate work.
