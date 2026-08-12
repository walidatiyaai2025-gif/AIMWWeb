# UX-010 — Accessibility Hardening Batch 9 (Tasks 081–090)

This batch adds ten test-only guards for remaining ARIA flow, position, level, index-text, braille, and deprecated metadata contracts.

## Completed tasks

- [x] UX010-HARD-081 Validate every `aria-flowto` ID reference.
- [x] UX010-HARD-082 Require `aria-posinset` to be a positive integer.
- [x] UX010-HARD-083 Require `aria-setsize` to be `-1` or a positive integer.
- [x] UX010-HARD-084 Require finite `aria-posinset` values not to exceed finite `aria-setsize`.
- [x] UX010-HARD-085 Require every `aria-level` value to be a positive integer.
- [x] UX010-HARD-086 Reject empty `aria-colindextext` metadata.
- [x] UX010-HARD-087 Reject empty `aria-rowindextext` metadata.
- [x] UX010-HARD-088 Reject empty `aria-braillelabel` metadata.
- [x] UX010-HARD-089 Reject empty `aria-brailleroledescription` metadata.
- [x] UX010-HARD-090 Reject deprecated `aria-dropeffect` and `aria-grabbed` usage.

## Execution coverage

The browser suite runs these checks across all public and authenticated routes in the UX route catalog.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.
