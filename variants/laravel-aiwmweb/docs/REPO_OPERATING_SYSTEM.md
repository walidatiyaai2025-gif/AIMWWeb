# Laravel AIWMWeb Repository Operating System

Tracking: #589  
Parity/convergence authority: #257

This layer adds FOOD-style execution discipline around the existing Laravel AIWMWeb acceptance, convergence, parity and security gates. It does not replace or weaken those workflows.

## Core contract

1. One execution unit = one owner = one branch = one pull request.
2. Continue an existing branch/PR for the same unit. Never create a replacement while a valid worker lease is active.
3. Umbrellas coordinate; leaf issues are claimable.
4. Dependencies must be explicit. Blocked units use `handoff:blocked`.
5. Exact-head GitHub CI/evidence is authoritative.
6. Laravel execution work stays inside `variants/laravel-aiwmweb/**`. Repository-operating-system files under `.github/**` are the normal exception.
7. Do not mix Laravel and ASP.NET runtime changes in one PR.
8. A merged leaf PR hands off to the next ready unit and its merged task branch is deleted when safe.

## Single-command autonomous handoff

The canonical chat/operator command is:

`حرك AI Web Laravel`

`AI WEB LARAVEL AUTO-HANDOFF` is an equivalent English alias.

Every invocation means **execute**, not merely report. Reconstruct live GitHub state and continue the project from the durable repository checkpoint without asking the owner to restate context.

Priority order on every invocation:

1. Read `AGENTS.md`, Issue #257, this runbook, the exact head of `worker/laravel-aiwmweb-closure-composition`, the canonical parity reconciliation, open Laravel PRs, and open handoff leaves.
2. Continue an already-active leaf first when its lease/branch/PR lineage is valid.
3. If its exact-head CI is red, repair the failure on the same branch and PR. Do not create a CI-fix/retry/v2/final branch.
4. If the active leaf is stale under the lease policy, reclaim the same execution unit and reuse its existing branch/PR whenever technically possible.
5. Otherwise claim `handoff:next`, then the oldest eligible `handoff:ready` leaf.
6. If the queue is empty but canonical reconciliation still has `PENDING > 0`, run the `advance` behavior: choose the first canonical PENDING operation, race-check existing Issue/branch/PR ownership for that operation ID, reuse any existing lineage, and create at most one new leaf only when none exists.
7. Implement the complete vertical slice, add deterministic evidence, run the official reconciliation/materialization path, pass all exact-head required gates, merge normally into `worker/laravel-aiwmweb-closure-composition`, close the leaf, and advance again.
8. Continue the loop within the current invocation until the project is complete, a genuine external/owner-only blocker is reached, or the execution environment cannot safely continue. Before any stop, write the exact checkpoint to GitHub and leave the next executable unit discoverable.

### Connection/interruption recovery

GitHub is the durable checkpoint. After any interrupted response, lost connection, tool retry, or new chat, never restart from remembered prose. Re-read the live composition head, #257, active/next/ready leaves, branch/PR heads, and exact-head CI, then resume from the first unfinished state. Completed work must not be reimplemented.

### Branch/PR collision rules

- One canonical operation = one leaf issue = one branch = one PR.
- Reuse the branch and PR already associated with that operation.
- A stale base is synchronized on the same lineage with normal ancestry-preserving Git operations; it is not a reason to create `-retry`, `-v2`, `-final`, or parallel recovery branches.
- A replacement/recovery branch is exceptional and allowed only when the existing lineage is technically unrecoverable while unique commits must be preserved; the leaf issue must record the reason and supersession evidence first.
- Never force-push, squash away shared tested history, or bypass exact-head gates to make convergence appear green.

### Completion condition

The project is not complete because the queue is empty. Full closure requires all of the following at the authoritative composition head:

- canonical denominator remains 931 operations;
- `PENDING = 0`;
- no unresolved `BLOCKED` operation;
- no duplicate operation IDs, unpushed countable sources, or placeholder terminals;
- owner/manual acceptance queue is empty except explicitly admitted external evidence;
- all required PR and post-merge exact-head workflows are green;
- final release/integration follows #257/PCC governance; do not blindly merge the Laravel composition branch to `main` before the closure/release gate authorizes it.

## Handoff labels

| Label | Meaning |
| --- | --- |
| `handoff:ready` | Dependencies clear; worker may claim. |
| `handoff:next` | Current preferred next unit. |
| `handoff:active` | Active lease; do not duplicate/take over. |
| `handoff:blocked` | External dependency prevents progress. |
| `handoff:umbrella` | Coordination only; never directly claimed. |
| `handoff:done` | Integrated execution unit. |
| `migration:approved` | Explicit approval for destructive migration semantics. |

The handoff workflow creates/repairs these labels automatically.

## Worker lifecycle

```text
READY -> NEXT -> CLAIM/ACTIVE -> BRANCH -> IMPLEMENT
      -> PR -> REQUIRED GATES -> MERGE -> DONE
      -> dependency unlock -> NEXT
```

If a worker cannot continue, release the issue back to `handoff:ready`. Explicit stale reclaim is allowed only after at least 30 minutes of issue inactivity; the workflow refuses earlier takeovers.

Independent ready issues may run in parallel. Collision safety is per leaf issue/branch/PR, not a global single-worker lock.

## Workflow-dispatch commands

Run **Laravel AIWMWeb Handoff** with:

- `bootstrap`: ensure handoff labels exist.
- `promote`: choose the oldest eligible ready issue as `handoff:next`.
- `advance`: if no ready/next/active leaf exists, derive the first canonical PENDING operation from the composition reconciliation, race-check existing ownership, create at most one missing leaf, and promote it.
- `claim` + issue number: acquire an active lease.
- `heartbeat` + issue number: record continued ownership.
- `release` + issue number: return the unit to ready.
- `reclaim` + issue number: recover a stale active unit after the 30-minute guard.

Merged PRs automatically process leaf issues referenced with `Closes #N`, `Fixes #N` or `Resolves #N`. Use non-closing `Refs #257` for the authority umbrella.

## Required gates

The existing workflows remain authoritative:

- Laravel AIWMWeb Acceptance
- Laravel AIWMWeb Convergence Preflight
- Laravel AIWMWeb Parity Reconciliation
- Laravel AIWMWeb Cross-Domain Security
- Laravel AIWMWeb Repo Hygiene
- PR Plan Link

The Repo Hygiene gate adds policy controls for branch naming, mixed Laravel/ASP.NET scope, committed generated/local payloads, high-confidence secrets/private keys, unsafe workflow primitives, destructive migration approval, Composer/NPM lock-file consistency and oversized tracked files.

## Dependency and security maintenance

Dependabot tracks Composer, NPM and GitHub Actions weekly. Nightly Health performs a clean install from lock files, Composer/NPM production security audits, Pint, a fresh SQLite migration, the full Laravel suite, TypeScript checks/tests and a production frontend build.

## Branch cleanup

After a successful merge, task branches with managed prefixes are deleted automatically. The permanent integration branches `main`, `lead/laravel-aiwmweb-final-convergence`, and `worker/laravel-aiwmweb-closure-composition` are never deleted by automation.

## Repository settings outside versioned files

Branch/ruleset administration is separate from repository code. Configure required status checks for the relevant integration branch so merges cannot bypass acceptance/security/hygiene gates, and require conversation resolution/review as appropriate. Do not weaken exact-head gates to make a PR green.
