# TEAM PROGRESS 182 — AI-003 Prompt template registry

## Status
Implemented on the release branch. Corrected implementation gates are green; final release-head Build and .NET Build Verification must pass before merge.

## Problem closed
The original `AIPromptRegistry` was an in-memory dictionary of ten fixed English/Arabic instructions. It provided read-only API/runtime lookup but had no persistence, administrator management, revision history, disable governance, or restore workflow.

## Delivered
- Added a durable atomic prompt registry in the managed application Data directory.
- Seeded the existing ten bilingual built-in prompts as revision 1 without overwriting persisted customizations.
- Added separate runtime (`IAIPromptRegistry`) and management (`IAIPromptTemplateStore`) contracts.
- Added stable-key custom template creation and bilingual editing.
- Added enable/disable behavior that is versioned and enforced by runtime lookup.
- Added append-only revision history with timestamp, actor, change type, bilingual titles/prompts, and enabled state.
- Added restore-as-new-revision semantics; history is never rewritten by restore.
- Added idempotent no-change saves to avoid meaningless revision inflation.
- Added administrator-only bilingual UI at `/settings/ai-prompts` and administrator-only Settings navigation.
- Added corrupt-file quarantine/recovery and atomic temp-file replacement.
- Added regression tests covering restart persistence, bilingual seeds, idempotency, update/restore, disable behavior, and validation.

## Persistence decision
Prompt templates remain application-global, matching the existing singleton registry semantics. A managed atomic JSON catalog is used instead of adding relational tables solely for configuration history. This keeps the synchronous runtime lookup contract intact and avoids a schema migration while still surviving application restart.

## Quality receipts
Initial head was intentionally blocked after CI found a compile-time schema initializer issue:
- Build #1355 — FAILED
- .NET Build Verification #968 — FAILED

Corrected implementation head:
- Build #1357 — SUCCESS
- .NET Build Verification #969 — SUCCESS

Final release-head Build and .NET Build Verification are required before merge.

## Release
`155.128.0`
