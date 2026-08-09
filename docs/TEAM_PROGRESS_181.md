# TEAM PROGRESS 181 — AI-002 AI provider configuration

## Status
Implemented on the release branch. Core implementation gates are green; final release-head Build and .NET Build Verification must pass before merge.

## Problem closed
The application already had an AI settings model and encrypted secret storage, but the production AI providers still read credentials and models directly from server configuration, provider priority/fallback settings were not enforced by the runtime, and administrators had no secure UI for managing provider credentials.

## Delivered
- Added an administrator-only bilingual AI provider configuration workspace under Settings.
- Reused the existing `IApplicationSettingsService` and AES-GCM `ISecretProtectionService`; no parallel secret store or schema migration was introduced.
- Added secure runtime credential retrieval that decrypts only when a provider is executing.
- Added explicit credential removal while preserving the existing safe rule that a blank input keeps the stored secret unchanged.
- Preserved legacy OpenAI protected-key compatibility.
- Added settings-backed OpenAI, Gemini, and Puter production adapters.
- Persisted provider Enabled, Priority, Model, global AI Enabled, and AutomaticFallback settings are now enforced by the production orchestrator.
- Existing server configuration remains a backward-compatible credential/model fallback when no encrypted database key is present.
- Puter endpoint configuration remains server-side and is rejected unless it is an absolute HTTPS URL.
- Groq, OpenRouter, and Ollama settings remain visible/configurable without claiming runtime adapters that are not registered in this release.
- Provider failure messages avoid returning remote response bodies or stored credentials to the UI.

## Security and consistency rules
- `/settings/ai-providers` requires the `Administrator` role.
- The main Settings page exposes the AI-provider navigation entry only to administrators.
- Password/token inputs are write-only from the UI perspective; saved plaintext is never rehydrated into the component.
- Keys are protected before persistence and decrypted only through the application settings service.
- Empty credential input means preserve, not clear; clearing requires an explicit administrator action.
- Global application AI disablement short-circuits execution before a provider request.
- Provider fallback is deterministic by persisted priority and can be disabled explicitly.

## Regression coverage
- Protected credential save/read and no-plaintext persistence.
- Blank-key update preserves the existing credential.
- Explicit credential removal removes the stored key without changing provider enablement.
- Provider priority controls execution order.
- Automatic fallback disabled stops after the highest-priority enabled provider.
- Automatic fallback enabled proceeds to the next enabled provider.

## Validation receipts
Implementation head:
- Build #1336 — SUCCESS
- .NET Build Verification #962 — SUCCESS

Final release-head Build and .NET Build Verification are required before merge.

## Release
`155.127.0`
