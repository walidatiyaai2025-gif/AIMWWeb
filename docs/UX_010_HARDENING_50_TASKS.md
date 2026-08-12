# UX-010 — Accessibility Hardening Batch 5 (Tasks 041–050)

This batch extends the active UX-010 browser regression work with ten additional native association and landmark accessibility checks. It is intentionally test-only and stacked on top of the fourth hardening batch.

## Completed tasks

- [x] UX010-HARD-041 Validate every explicit `label[for]` target exists and is a labelable control.
- [x] UX010-HARD-042 Validate every `output[for]` ID reference resolves to an existing element.
- [x] UX010-HARD-043 Validate every `input[list]` reference resolves to an existing `datalist`.
- [x] UX010-HARD-044 Validate explicit HTML `form` associations resolve to an existing `form` element.
- [x] UX010-HARD-045 Validate table-cell `headers` references resolve to existing `th` elements.
- [x] UX010-HARD-046 Validate every `usemap` reference resolves to an existing named `map`.
- [x] UX010-HARD-047 Require image-map link areas to expose non-empty `alt` text.
- [x] UX010-HARD-048 Require every visible `details` disclosure to provide a direct `summary` element.
- [x] UX010-HARD-049 Require accessible names when multiple visible `nav` landmarks are present.
- [x] UX010-HARD-050 Require accessible names when multiple visible `aside` landmarks are present.

## Execution coverage

The browser regression suite applies this batch to every route in `UxRouteCatalog.PublicRoutes` and `UxRouteCatalog.AuthenticatedRoutes`, using the existing isolated loopback host and seeded Administrator browser state.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.
