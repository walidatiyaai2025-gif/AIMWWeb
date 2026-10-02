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
- PR Plan Link

The Repo Hygiene gate adds policy controls for branch naming, mixed Laravel/ASP.NET scope, committed generated/local payloads, high-confidence secrets/private keys, unsafe workflow primitives, destructive migration approval, Composer/NPM lock-file consistency and oversized tracked files.

## Dependency and security maintenance

Dependabot tracks Composer, NPM and GitHub Actions weekly. Nightly Health performs a clean install from lock files, Composer/NPM production security audits, Pint, a fresh SQLite migration, the full Laravel suite, TypeScript checks/tests and a production frontend build.

## Branch cleanup

After a successful merge, task branches with managed prefixes are deleted automatically. The permanent integration branches `main`, `lead/laravel-aiwmweb-final-convergence`, and `worker/laravel-aiwmweb-closure-composition` are never deleted by automation.

## Repository settings outside versioned files

Branch/ruleset administration is separate from repository code. Configure required status checks for the relevant integration branch so merges cannot bypass acceptance/security/hygiene gates, and require conversation resolution/review as appropriate. Do not weaken exact-head gates to make a PR green.
