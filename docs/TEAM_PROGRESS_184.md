# TEAM PROGRESS 184 — AI-004 Structured AI suggestions

## Status
IN PROGRESS on `agent/ai-004-structured-suggestions`.

## Tracking
- Issue #42 — AI-004: structured AI suggestions with before/after evidence.
- Target release: `155.129.0` after green implementation and release-head CI.

## Implemented so far
- Added `AISuggestion` as a reviewable application contract containing authoritative Before, proposed After, Explanation, Confidence, and AffectedFields.
- Added strict JSON generation instructions layered on top of the selected AI-003 prompt template.
- Added fail-closed parsing for malformed JSON, missing fields, invalid confidence, empty affected fields, oversized values, and excessive affected-field lists.
- Preserved the application-owned original `Before` value even if an AI response attempts to supply its own source value.
- Upgraded AI Center to render structured evidence, confidence, affected-field badges, and before/after comparison in Arabic/English.
- Approval submissions now retain explanation, confidence, and affected fields in the existing before/after JSON rather than introducing a parallel approval store.
- Session history preserves and restores the entire structured suggestion.
- Added CSS isolation for the evidence panel.
- Added regression tests for valid/fenced JSON, evidence integrity, field normalization, invalid confidence, malformed/incomplete payloads, and Arabic instruction selection.

## Architecture boundary
This task builds on AI-003 prompt governance and AI-005/AI-006 approval/execution. It does not change prompt persistence, approval storage schema, or WordPress execution policy.

## Validation
Pending GitHub Actions Build and .NET Build Verification on the implementation head.
