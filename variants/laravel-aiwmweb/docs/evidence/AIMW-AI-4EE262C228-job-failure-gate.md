# AIMW-AI-4EE262C228 — Persistence JobFailureGate.CanStartAsync

## Canonical source

- Operation: `AIMW-AI-4EE262C228`
- Kind/domain: `background_job` / `ai`
- Source behavior: `src/AIWordPressManager.Persistence/Jobs/JobFailureGate.cs`
- Laravel destination: `backend/app/Jobs/JobFailureGate.php`
- Focused test: `backend/tests/Unit/JobFailureGateTest.php`

## Recovery rationale

The Laravel runtime already implements this concrete persistence gate through the same `JobFailureGate` adapter used for the canonical `IJobFailureGate.CanStartAsync` interface operation. PR #380 deliberately scoped its generator source only to `AIMW-AI-4C84DDBEEB`, preventing accidental collateral terminalization. This recovery binds the already-implemented concrete source operation to the same production adapter without changing runtime behavior.

## Preserved source semantics

The adapted gate preserves the concrete source decisions: pausing can be disabled; the latest configured threshold must all be failures; a non-failure breaks the streak; the pause deadline is based on the latest failure completion/update; automatic resume reopens the gate after the pause window; manual resume stays closed; and operator output reports the remaining pause.

For the migrated Laravel AI suggestion job family, durable failure history is read through `Suggestion::query()` and filtered by `site_id`. The model's existing tenant scope remains authoritative, so this evidence adds no alternate unscoped persistence path.

## Deterministic acceptance

`JobFailureGateTest` pins this exact operation ID and concrete source identity to the production adapter and continues to cover disabled gating, threshold behavior, streak reset, pause metadata, automatic resume, and manual-resume behavior.

No live provider, WordPress, payment, DNS, or owner/manual evidence is required for this reconciliation-only recovery. Exact-head repository CI remains the execution authority.
