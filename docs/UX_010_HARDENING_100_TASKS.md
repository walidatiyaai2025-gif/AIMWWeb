# UX-010 — Accessibility Hardening Batch 10 (Tasks 091–100)

This final hardening batch adds ten test-only guards for DOM identifiers, fragment references, keyboard metadata, editable surfaces, and inline click operability.

## Completed tasks

- [x] UX010-HARD-091 Reject empty `id` attributes.
- [x] UX010-HARD-092 Reject whitespace inside `id` attribute values.
- [x] UX010-HARD-093 Require same-page fragment links to resolve to existing element IDs.
- [x] UX010-HARD-094 Require raw `tabindex` values to parse as integers.
- [x] UX010-HARD-095 Reject multiple visible autofocus targets.
- [x] UX010-HARD-096 Reject autofocus targets that are disabled or hidden.
- [x] UX010-HARD-097 Validate `accesskey` as one-character tokens.
- [x] UX010-HARD-098 Reject duplicate visible accesskey tokens.
- [x] UX010-HARD-099 Require visible editable regions to expose accessible names.
- [x] UX010-HARD-100 Require visible inline-click targets to remain natively interactive, ARIA-interactive, or keyboard focusable.

## Execution coverage

The browser suite runs these checks across all public and authenticated routes in the UX route catalog.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.

## Hardening milestone

With the preceding nine batches, `UX010-HARD-001` through `UX010-HARD-100` are now represented as ten isolated 10-task hardening slices stacked above the UX-010 base regression-gate work.
