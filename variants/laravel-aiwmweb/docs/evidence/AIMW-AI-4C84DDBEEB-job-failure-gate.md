# AIMW-AI-4C84DDBEEB — IJobFailureGate.CanStartAsync

## Canonical source

- Source contract: `src/AIWordPressManager.Application/Abstractions/Persistence/IJobFailureGate.cs`
- Source behavior: `src/AIWordPressManager.Persistence/Jobs/JobFailureGate.cs`
- Laravel destination: `backend/app/Jobs/JobFailureGate.php`
- Runtime wiring: `backend/app/Jobs/GenerateSuggestionJob.php`

## Parity contract

Laravel preserves the source decision semantics for the currently migrated AI suggestion job family:

1. failure pausing can be disabled;
2. the default threshold is three consecutive failures;
3. any successful/ready terminal run inside the latest threshold breaks the failure streak;
4. a qualifying streak returns `canRun=false`, a deterministic UTC `resumeAtUtc`, and an operator-readable message;
5. the default pause is 15 minutes;
6. automatic resume opens the gate after the pause window;
7. disabling auto-resume keeps the gate closed after the window;
8. settings are clamped to the source bounds (threshold 1..20, pause minutes 1..1440);
9. history is tenant-safe through the already tenant-scoped queue middleware and site-owned through `suggestions.site_id`;
10. an unknown/non-migrated job family fails open rather than claiming another canonical store operation.

## Runtime behavior

`GenerateSuggestionJob` resolves its tenant-owned Suggestion first, evaluates the gate with the Suggestion's `site_id`, and, when paused, releases the queued job without setting `running`, without marking it `failed`, and without making an AI provider call. When allowed, the existing generation path is unchanged.

The implementation deliberately reuses the existing durable `suggestions` lifecycle (`queued/running/ready/failed`) rather than creating a competing ExecutionJob store, because migration of other canonical job stores is outside this operation's ownership.

## Deterministic tests

`backend/tests/Unit/JobFailureGateTest.php` covers:

- feature disabled;
- fewer failures than threshold;
- successful run resets the consecutive streak;
- threshold pause + resume metadata;
- auto-resume after the pause window;
- manual-resume mode remaining closed after the window.

Exact-head CI is the authoritative execution evidence for this branch/PR. No merge is authorized by this worker.

## Slot 0030 recovery hardening

The recovered Laravel gate reads failure history through `Suggestion::query()` instead of a raw query builder. `Suggestion` inherits the variant's `BelongsToTenant` model contract, so the history lookup is constrained by the mandatory tenant scope in addition to the queue job's tenant context. Canonical operation: `AIMW-AI-4C84DDBEEB`.
