# TEAM PROGRESS 183 — AI-003 runtime hardening

## Status
Patch implementation is complete on PR #41 (`agent/ai-003-runtime-hardening`) for release `155.128.1`. The implementation head passed both required GitHub Actions gates. Final release-head validation is still required after documentation and canonical-plan reconciliation.

## Team coordination
AI-003 itself was completed by the team in PR #38 using the accepted `VersionedAIPromptRegistry` architecture. A parallel draft implementation in PR #39 was intentionally closed rather than merged after `main` advanced, avoiding duplicate persistence models and preserving the team's accepted design.

This patch contains only non-overlapping post-merge hardening discovered during review of the current `main` implementation.

## Defects fixed
1. AI Center serialized `IAIPromptRegistry.GetAll()` even though it returns a dictionary, then only loaded prompts when the JSON root was an array. The prompt library could therefore appear empty even with enabled templates.
2. AI Center and `/api/ai/generate` used direct `Get()` calls. Disabled templates resolve to no prompt text and unknown keys can resolve differently from enabled runtime discovery, so manually entered unavailable keys were not rejected before orchestration.

## Delivered
- Added default `IAIPromptRegistry.TryGet` safe lookup using the enabled `GetAll()` catalog as the runtime source of truth.
- Updated AI Center to enumerate enabled prompt keys directly without dictionary-to-JSON conversion.
- Added readable prompt titles derived from stable keys without changing persisted prompt metadata.
- Updated AI Center generation to fail before orchestration when a prompt is missing or disabled.
- Updated `/api/ai/generate` to return `400 Bad Request` for missing/disabled prompt keys.
- Preserved direct custom system prompts when no prompt key is supplied.
- Added regression coverage for enabled, disabled, and unknown safe lookup behavior.
- Bumped the patch release from `155.128.0` to `155.128.1`.

## Scope intentionally unchanged
- `VersionedAIPromptRegistry` atomic JSON persistence.
- Corrupt-file quarantine/recovery.
- Built-in bilingual seed behavior.
- Stable prompt keys and administrator management UI.
- Idempotent unchanged saves.
- Append-only revision history and restore-as-new-revision semantics.

## Validation receipts
Implementation head `c673e4473e34d541048dd2606c14965329085ee4`:
- Build #1370 — SUCCESS
- .NET Build Verification #978 — SUCCESS

## Release-head rule
Do not merge PR #41 until the latest PR head reports SUCCESS for both Build and .NET Build Verification after this reconciliation.

## Tracking
- Issue #40
- PR #41
- Release `155.128.1`
