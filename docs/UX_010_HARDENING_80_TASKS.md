# UX-010 — Accessibility Hardening Batch 8 (Tasks 071–080)

This batch adds ten test-only guards for native HTML grouping, table, disclosure, and output semantics.

## Completed tasks

- [x] UX010-HARD-071 Require visible fieldsets containing controls to provide a legend or accessible name.
- [x] UX010-HARD-072 Require every `optgroup` to provide a non-empty label.
- [x] UX010-HARD-073 Reject empty table captions when captions are present.
- [x] UX010-HARD-074 Validate `th[scope]` values against row/column scope semantics.
- [x] UX010-HARD-075 Require visible `meter` elements to expose accessible names.
- [x] UX010-HARD-076 Require visible `progress` elements to expose accessible names.
- [x] UX010-HARD-077 Require visible `output` elements to expose accessible names.
- [x] UX010-HARD-078 Require visible `summary` controls to expose accessible names.
- [x] UX010-HARD-079 Require accessible names when multiple visible forms containing controls are present.
- [x] UX010-HARD-080 Detect visible native radio groups that expose no labeled option.

## Execution coverage

The browser suite runs these checks across all public and authenticated routes in the UX route catalog.

## Compatibility boundary

No production business, authentication, tenant, database, persistence, API, AI, approval, or WordPress execution behavior is changed. This batch adds regression detection only.
