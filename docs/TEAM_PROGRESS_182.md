# TEAM PROGRESS 182 — AI-003 Prompt template registry

## Status
Implementation and release reconciliation are complete on PR #39 (`agent/ai-003-prompt-registry`) for release `155.128.0`. The implementation head passed both required GitHub Actions gates. The final PR head must also remain green before merge; PR checks are the authoritative final validation evidence.

## Problem closed by this slice
The application already exposed a built-in prompt dictionary and basic prompt API, but administrators could not edit prompts, disable them, keep bilingual revisions, or restore an earlier revision. AI Center also serialized the dictionary and then attempted to read it as a JSON array, which could leave the prompt library empty.

## Delivered
- Added administrator-managed bilingual prompt overrides stored in the existing `ApplicationSettings` store; no database schema migration is required.
- Added immutable version history with a maximum of 50 retained saved revisions per prompt.
- Every save creates a new version instead of overwriting history.
- Restoring a saved revision creates a new version from that snapshot.
- Built-in prompts are represented as version `0` and can be restored into a new managed version.
- Added validation for prompt keys and required English/Arabic text, with an 8,000-character limit per localized prompt.
- Added enabled/disabled runtime state; disabled prompts are excluded from normal discovery and rejected for generation.
- Added `AIPromptTemplateService` to merge built-in templates with persisted administrator overrides.
- Updated `/api/ai/prompts` to return the effective managed registry.
- Added administrator-only history, save, and restore prompt endpoints.
- Updated `/api/ai/generate` to resolve the effective managed prompt and reject missing/disabled prompt keys.
- Updated AI Center to use the managed registry and removed the dictionary-as-array parsing defect.
- Added administrator-only bilingual `/settings/ai-prompts` workspace with search, edit/create, enable/disable, version history, and restore actions.
- Added the prompt-registry entry to the administrator Settings navigation.
- Reconciled the canonical feature registry to `completed` at version `155.128.0` and added release notes.

## Security and consistency rules
- Prompt mutation/history endpoints and the management page require the `Administrator` role.
- Prompt keys allow only ASCII letters, digits, `.`, `_`, and `-`, with a maximum of 80 characters.
- Both English and Arabic prompt text are mandatory for managed versions.
- Restore is append-only: existing history is never rewritten.
- Runtime generation never silently executes a disabled or unknown managed prompt.
- Persisted prompt history uses the existing settings row rather than introducing a parallel database schema.

## Regression coverage
`AIPromptTemplateConfigurationTests` covers:
- version creation and immutable history,
- restore-as-new-version semantics,
- localized English/Arabic effective prompt resolution,
- disabled-prompt runtime exclusion,
- built-in baseline restoration,
- prompt key and bilingual text validation.

## Validation
Implementation head:
- Build #1368 — SUCCESS
- .NET Build Verification #976 — SUCCESS

Release-head rule:
- Do not merge PR #39 unless the latest PR head reports SUCCESS for both Build and .NET Build Verification.
