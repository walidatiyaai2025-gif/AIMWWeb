# UX-010 — Accessibility Hardening Batch 4 (Tasks 031–040)

This batch extends the active UX-010 browser regression work with ten additional accessibility semantics checks. It is intentionally test-only and stacked on top of the third hardening batch.

## Completed tasks

- [x] UX010-HARD-031 Validate `aria-hidden` as an explicit boolean ARIA state.
- [x] UX010-HARD-032 Validate `aria-atomic` as an explicit boolean ARIA state.
- [x] UX010-HARD-033 Validate `aria-invalid` values (`false`, `true`, `grammar`, `spelling`).
- [x] UX010-HARD-034 Validate every `aria-relevant` token against the supported live-region change set.
- [x] UX010-HARD-035 Reject empty `aria-valuetext` attributes.
- [x] UX010-HARD-036 Reject empty `aria-roledescription` attributes.
- [x] UX010-HARD-037 Reject empty `aria-description` attributes.
- [x] UX010-HARD-038 Reject empty `aria-placeholder` attributes.
- [x] UX010-HARD-039 Reject empty `aria-keyshortcuts` attributes.
- [x] UX010-HARD-040 Reject empty `aria-label` attributes when explicitly present.

## Execution coverage

The browser regression suite applies this batch to every route in `UxRouteCatalog.PublicRoutes` and `UxRouteCatalog.AuthenticatedRoutes`, using the existing isolated loopback host and seeded Administrator browser state.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.
