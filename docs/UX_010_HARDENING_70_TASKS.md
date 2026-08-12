# UX-010 — Accessibility Hardening Batch 7 (Tasks 061–070)

This batch adds ten test-only semantic guards for named landmarks and composite ARIA widgets.

## Completed tasks

- [x] UX010-HARD-061 Require visible `role=region` landmarks to expose accessible names.
- [x] UX010-HARD-062 Require visible `role=form` landmarks to expose accessible names.
- [x] UX010-HARD-063 Require visible `role=application` regions to expose accessible names.
- [x] UX010-HARD-064 Require visible ARIA radiogroups to expose accessible names.
- [x] UX010-HARD-065 Require every ARIA radio to expose explicit boolean `aria-checked` state.
- [x] UX010-HARD-066 Require visible radiogroups with enabled radios to expose a keyboard entry radio.
- [x] UX010-HARD-067 Require visible ARIA sliders to expose accessible names.
- [x] UX010-HARD-068 Require ARIA sliders to expose numeric `aria-valuenow` values.
- [x] UX010-HARD-069 Require visible ARIA tree items to expose accessible names.
- [x] UX010-HARD-070 Require expandable tree items with child groups to expose explicit boolean `aria-expanded` state.

## Execution coverage

The browser suite runs these checks across all public and authenticated routes in the UX route catalog.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.
