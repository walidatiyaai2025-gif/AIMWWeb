# TEAM PROGRESS 184 — AI-004 Structured AI suggestions

## Status
IMPLEMENTED on `agent/ai-004-structured-suggestions`; final release-head CI is required before merge.

## Tracking
- Issue #42 — AI-004: structured AI suggestions with before/after evidence.
- PR #43 — AI-004: structured AI suggestions with reviewable evidence.
- Release: `155.129.0`.

## Delivered
- Added `AISuggestion` as a reviewable application contract containing authoritative Before, proposed After, Explanation, Confidence, and AffectedFields.
- Added strict JSON generation instructions layered on top of the selected AI-003 prompt template.
- Added fail-closed parsing for malformed JSON, missing fields, invalid confidence, empty affected fields, oversized values, and excessive affected-field lists.
- Preserved the application-owned original `Before` value even if an AI response attempts to supply its own source value.
- Upgraded AI Center to render structured evidence, confidence, affected-field badges, and before/after comparison in Arabic/English.
- Approval submissions retain explanation, confidence, and affected fields in the existing before/after JSON rather than introducing a parallel approval store.
- Session history preserves and restores the entire structured suggestion.
- Added CSS isolation for the evidence panel.
- Added regression tests for valid/fenced JSON, evidence integrity, field normalization, invalid confidence, malformed/incomplete payloads, and Arabic instruction selection.

## Architecture boundary
This task builds on AI-003 prompt governance and AI-005/AI-006 approval/execution. It does not change prompt persistence, approval storage schema, or WordPress execution policy.

## Validation history
The first implementation head was correctly blocked by CI because the JSON schema inside an interpolated C# raw string used insufficient `$` escaping:
- .NET Build Verification #983 — FAILED with `CS9006` / `CS1733`; tests were skipped after compile failure.

Corrected implementation head `e127e9fab41682dfc530a8b234156f8b44972b4a`:
- Build #1376 — SUCCESS.
- .NET Build Verification #984 — SUCCESS.

The final release head containing version, release notes, team progress, and canonical status must repeat both gates successfully before merge.
