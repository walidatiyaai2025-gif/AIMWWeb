# UX-010 — Accessibility Hardening Batch 2 (Tasks 011–020)

This batch extends the active UX-010 browser regression work with ten additional accessibility semantics checks. It is intentionally test-only and stacked on top of the first hardening batch.

## Completed tasks

- [x] UX010-HARD-011 Validate `aria-current` values against the supported enumeration.
- [x] UX010-HARD-012 Validate `aria-haspopup` values against the supported enumeration.
- [x] UX010-HARD-013 Validate `aria-live` values (`off`, `polite`, `assertive`).
- [x] UX010-HARD-014 Validate `aria-orientation` values (`horizontal`, `vertical`).
- [x] UX010-HARD-015 Validate `aria-sort` values (`none`, `ascending`, `descending`, `other`).
- [x] UX010-HARD-016 Validate `aria-autocomplete` values (`none`, `inline`, `list`, `both`).
- [x] UX010-HARD-017 Require visible `role=heading` elements to expose an integer `aria-level` from 1 through 6.
- [x] UX010-HARD-018 Require visible `input[type=image]` controls to expose non-empty alternative text.
- [x] UX010-HARD-019 Validate `aria-disabled` as an explicit boolean ARIA state.
- [x] UX010-HARD-020 Validate numeric `aria-valuemin`, `aria-valuemax`, and `aria-valuenow` values plus min/max/range consistency.

## Execution coverage

The browser regression suite applies this batch to every route in `UxRouteCatalog.PublicRoutes` and `UxRouteCatalog.AuthenticatedRoutes`, using the existing isolated loopback host and seeded Administrator browser state.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.
