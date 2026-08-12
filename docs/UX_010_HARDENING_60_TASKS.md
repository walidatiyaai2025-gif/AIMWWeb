# UX-010 — Accessibility Hardening Batch 6 (Tasks 051–060)

This batch extends the active UX-010 browser regression work with ten additional interactive-role accessibility checks. It is intentionally test-only and stacked on top of the fifth hardening batch.

## Completed tasks

- [x] UX010-HARD-051 Require visible `role=link` elements to expose accessible names.
- [x] UX010-HARD-052 Require enabled visible `role=link` elements to remain keyboard focusable.
- [x] UX010-HARD-053 Require visible ARIA menu items to expose accessible names.
- [x] UX010-HARD-054 Require each visible ARIA menu with enabled items to expose a keyboard entry item.
- [x] UX010-HARD-055 Require visible ARIA tabs to expose accessible names.
- [x] UX010-HARD-056 Require every ARIA tab to expose explicit boolean `aria-selected` state.
- [x] UX010-HARD-057 Require the selected visible enabled tab to remain keyboard focusable.
- [x] UX010-HARD-058 Require visible ARIA options to expose accessible names.
- [x] UX010-HARD-059 Require ARIA switches to expose explicit boolean `aria-checked` state.
- [x] UX010-HARD-060 Require visible ARIA comboboxes to expose accessible names and explicit boolean `aria-expanded` state.

## Execution coverage

The browser regression suite applies this batch to every route in `UxRouteCatalog.PublicRoutes` and `UxRouteCatalog.AuthenticatedRoutes`, using the existing isolated loopback host and seeded Administrator browser state.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.
