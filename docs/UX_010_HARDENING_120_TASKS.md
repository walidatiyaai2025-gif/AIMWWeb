# UX-010 — Accessibility Hardening Batch 13 (Tasks 116–120)

This focused five-task slice extends the stacked UX-010 accessibility regression coverage into remaining ARIA widget state, naming, and range contracts that are not represented by batches 1–12.

## Completed tasks

- [x] UX010-HARD-116 Require every visible `role=checkbox` to expose an accessible name and an explicit `aria-checked` value of `true`, `false`, or `mixed`.
- [x] UX010-HARD-117 Require every visible ARIA `role=meter` to expose an accessible name and numeric `aria-valuenow`.
- [x] UX010-HARD-118 Require every visible `role=scrollbar` to provide resolving `aria-controls` references and numeric `aria-valuenow`.
- [x] UX010-HARD-119 Require every visible focusable `role=separator` to expose numeric `aria-valuenow`.
- [x] UX010-HARD-120 Require every visible `role=searchbox` to expose an accessible name.

## Execution coverage

The browser suite applies this batch to every route in `UxRouteCatalog.PublicRoutes` and `UxRouteCatalog.AuthenticatedRoutes` after the normal UX audit preparation step.

## Standards intent

These guards cover required ARIA state/property contracts and accessible-name requirements for the targeted roles. Existing generic range validation remains in earlier hardening batches; this slice adds role-specific presence requirements without duplicating those numeric range checks.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding twelve batches, `UX010-HARD-001` through `UX010-HARD-120` are now represented in isolated stacked hardening slices above the UX-010 base regression-gate work.
