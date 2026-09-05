# Laravel AIWMWeb Final Owner Acceptance Queue

Authority: GitHub Issue #257  
Task: `AIMW-L-OWNER-LAST-QUEUE`  
Target variant: `LARAVEL_AIWMWEB`  
Canonical integration target: `worker/laravel-aiwmweb-closure-composition`

## Purpose

This is the single durable queue for work that is genuinely impossible to complete or verify in the cloud because it requires owner-controlled production credentials, live external accounts, DNS/TLS/deployment control, environment-specific evidence, or manual business/browser acceptance.

This file is **not** a backlog for code defects or unfinished engineering. Code defects, missing implementation, deterministic test gaps, security defects, merge conflicts, stale reconciliation, evidence-registration debt, broken CI, disposable WordPress/MySQL/Redis/Docker validation, and other repairable infrastructure remain cloud-actionable and MUST NOT be moved here.

Final Laravel closure remains blocked until every admitted queue item has genuine evidence, its PASS criteria are met, and the evidence is reconciled into the canonical closure authority.

## Current admitted owner-only items

**NONE.**

At bootstrap, no canonical operation/task has been proven cloud-complete with an external/manual requirement as its only remaining blocker. Therefore no owner interaction is required yet.

The current canonical operation ledger still contains cloud-actionable `PENDING` work. Those operations must continue through implementation/recovery/integration/evidence reconciliation before any owner-only deferral is admitted.

## Admission gate

An item may be added only when all of the following are true:

1. The source canonical operation/task is identified exactly.
2. All cloud-actionable implementation, tests, security checks, reconciliation, merge-conflict repair, and repository CI for that source are complete.
3. The only remaining evidence requires an owner-controlled or environment-specific boundary that cannot be truthfully produced by repository-approved cloud/disposable infrastructure.
4. The item does not weaken authentication, authorization, tenant isolation, signing/replay protection, approval, audit, idempotency, rate limiting, redaction, or truthful failure behavior.
5. The exact command/workflow and PASS criteria are taken from the live repository contract at the time of admission; stale commands are not copied forward blindly.
6. No real provider, WordPress, billing, mail, webhook, DNS/TLS, deployment, or manual evidence is fabricated.

## Required item schema

Every admitted item MUST contain all fields below.

| Field | Required content |
| --- | --- |
| `OWNER_ITEM_ID` | Stable queue identifier |
| `SOURCE_OPERATION_OR_TASK` | Exact canonical operation ID or task ID |
| `SOURCE_SHA` | Exact cloud-complete evidence-bearing SHA |
| `WHY_OWNER_ONLY` | Concrete external/manual reason; never a code/test/CI defect |
| `PREREQUISITES` | Exact cloud gates that must already be green |
| `OWNER_ACTION` | Minimal action the owner must perform |
| `EXACT_COMMAND_OR_WORKFLOW` | Repository-defined command/workflow, revalidated live before execution |
| `EXPECTED_EVIDENCE` | Receipt/log/screenshot/export/health response/provider record/etc. required |
| `PASS_CRITERIA` | Objective conditions for PASS |
| `FAIL_CRITERIA` | Conditions that must remain failed/blocking |
| `EVIDENCE_LOCATION` | Where the real evidence will be stored/referenced |
| `RECONCILIATION` | How evidence is registered and canonical reconciliation regenerated |
| `STATUS` | `WAITING_OWNER`, `EVIDENCE_RECEIVED`, `PASS`, or `FAIL` |

`PASS` is forbidden until the genuine expected evidence exists.

## External categories that may become queue items later

The following are **admission candidates only**, not current blockers and not PASS claims:

- injection of production-only Laravel/application/database/WordPress/provider secrets through the deployment secret mechanism;
- production database backup followed by the approved forward migration on the final immutable candidate image;
- deployment of the exact accepted source/image to the owner-controlled production environment;
- production DNS, TLS, reverse-proxy or ingress changes when the final deployment target actually requires them;
- real live provider, email, payment, webhook or WordPress-account transactions when a specific canonical operation requires evidence that disposable/sandbox infrastructure cannot supply;
- production `/health/ready` verification through the real deployment ingress;
- manual browser/business acceptance only where the live final acceptance policy explicitly requires human judgment that deterministic automation cannot replace.

Do not add a category above as an item until it is tied to an exact source operation/task and satisfies the admission gate.

## Repository-defined production procedures available for admitted items

These commands are current reference procedures from `docs/PRODUCTION_RUNTIME_RUNBOOK.md`. They MUST be re-fetched from the exact final candidate before owner execution.

### Production-style build and forward migration

From `variants/laravel-aiwmweb`:

```sh
cp runtime/.env.example runtime/.env
# Fill blank secret values outside version control.
docker compose --env-file runtime/.env -f runtime/docker-compose.yml build
docker compose --env-file runtime/.env -f runtime/docker-compose.yml up -d mysql redis
docker compose --env-file runtime/.env -f runtime/docker-compose.yml run --rm api php artisan migrate --force
docker compose --env-file runtime/.env -f runtime/docker-compose.yml up -d api web worker scheduler
```

Production upgrades require a database backup before `php artisan migrate --force`. `migrate:fresh` is CI-only and must not be used as a production shortcut.

### Connector packaging

```sh
runtime/scripts/package-connector.sh
```

Missing canonical Connector source must fail truthfully; source must never be synthesized merely to create an artifact.

### Deployment helper

```sh
runtime/scripts/deploy.sh
```

If final deployment uses the runbook health option, `HEALTH_READY_URL` must point to the genuine deployed `/health/ready` endpoint. A cloud/local response is not production evidence.

## Final owner stage protocol

The owner stage begins only after cloud closure reaches its final pre-owner gate: no repairable code/test/security/CI/reconciliation work remains, required canonical operations are terminal except explicitly admitted owner-only evidence obligations, and the exact candidate SHA has green repository-defined closure/security/parity/preflight/acceptance evidence.

Then process this queue once, in order:

1. Re-fetch the exact final candidate SHA and this queue.
2. Revalidate every item's command/workflow against that SHA.
3. Perform only the minimal owner-controlled actions listed in each item.
4. Capture genuine evidence without committing credentials, tokens, private keys, production data, or secrets.
5. Mark an item `PASS` only when its objective PASS criteria are met.
6. Register/reference the evidence through the canonical operation/task evidence mechanism.
7. Regenerate the parity/reconciliation artifacts with the repository's canonical generator and the live denominator; never hand-edit terminal counts.
8. Rerun the required exact-head closure evidence audit, security, parity reconciliation, convergence preflight, and acceptance gates.
9. Final project closure remains prohibited while any queue item is `WAITING_OWNER` or `FAIL`, or while any required canonical operation remains non-terminal.

## Reconciliation rule

Owner evidence never changes parity by itself. The corresponding canonical operation/task must still satisfy the generator's evidence requirements. Register exact pushed evidence/provenance through the live canonical evidence-source mechanism, regenerate with `tools/finalize_operation_parity.py` plus the currently configured explicit/focused evidence passes, and require the committed JSON/Markdown to match regeneration before final closure.

If the live denominator or generator pipeline changes, the live generator wins and this document must be updated rather than preserving stale counts or commands.
