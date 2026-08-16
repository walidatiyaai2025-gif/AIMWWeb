# UX-010 — Accessibility Hardening Batch 15 (Tasks 126–130)

This focused five-task slice continues the WAI-ARIA 1.2 accessible-name hardening work. It covers required naming semantics that were not represented in the preceding 125-task stack while preserving each role's permitted naming model.

## Completed tasks

- [x] UX010-HARD-126 Require every visible `role=button` to expose an accessible name from author-provided labeling or permitted contents.
- [x] UX010-HARD-127 Require every visible `role=radio` to expose an accessible name from author-provided labeling or permitted contents.
- [x] UX010-HARD-128 Require every visible `role=switch` to expose an accessible name from author-provided labeling or permitted contents.
- [x] UX010-HARD-129 Require every visible `role=grid` to expose an author-provided accessible name.
- [x] UX010-HARD-130 Require every visible `role=treegrid` to expose an author-provided accessible name.

## Execution coverage

The browser suite runs these checks across every public and authenticated route in the shared UX route catalog after the standard UX audit preparation step.

## Naming semantics

For button, radio, and switch roles, the audit accepts supported author labeling sources and role-permitted textual contents. For grid and treegrid roles, it intentionally requires author-provided naming sources rather than deriving a name from arbitrary descendant cell content.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding fourteen batches, `UX010-HARD-001` through `UX010-HARD-130` are represented in isolated stacked hardening slices above the UX-010 base regression-gate work.
