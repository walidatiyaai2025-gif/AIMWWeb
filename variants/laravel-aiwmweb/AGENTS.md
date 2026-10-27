# Laravel AIWMWeb Agent Extension

This file applies only inside `variants/laravel-aiwmweb/**`. It extends, and never replaces, the repository-root `AGENTS.md` and PCC governance.

## Authority order

1. Current owner instruction.
2. Live PCC `main`, PCC constitution, and authoritative routing packet.
3. Repository-root `AGENTS.md` and `.pcc/project-family.json`.
4. Issue #257 and `docs/REPO_OPERATING_SYSTEM.md`.
5. This variant extension.
6. `.ai/**` project knowledge and skills.

If any lower layer conflicts with a higher layer, stop the conflicting action and reconcile the higher authority. Chat memory is never canonical.

## Mandatory read sequence for Laravel work

Before implementation:
1. reconstruct live GitHub state;
2. verify the PCC route is `VARIANT -> LARAVEL_AIWMWEB -> variants/laravel-aiwmweb`;
3. read `.ai/PROJECT.md`;
4. read `.ai/CURRENT_STATE.md`, then verify dynamic facts against live GitHub and the canonical reconciliation;
5. read `.ai/SKILL_ROUTER.md`;
6. load only the skill files required for the task;
7. read the task Issue/PR and overlapping active work.

## Skill execution contract

- Select the minimum relevant skill set. Do not load every skill by default.
- Security-sensitive mutations, tenant-owned data, credentials, external providers, WordPress, billing, approvals, queues, or direct identifiers automatically require the security checks embedded in the relevant skill.
- A skill is an execution playbook, not an authority to bypass PCC, Issue #257, CI, or branch/PR rules.
- A skill may be improved only from verified repository evidence. Record the evidence in `.ai/DECISIONS.md`, `.ai/CONVENTIONS.md`, or `.ai/KNOWN_ISSUES.md` as appropriate.
- Never promote a guess, a one-off chat preference, or an unverified failure hypothesis into durable project knowledge.

## Durable learning loop

After a task is accepted or a root cause is proven:
1. classify the lesson as architecture decision, convention, recurring risk, or skill improvement;
2. attach Issue/PR/SHA or deterministic test evidence;
3. update the smallest durable knowledge file;
4. update the relevant skill only when the lesson changes the repeatable execution method;
5. keep transient branch/PR status out of durable files;
6. use `.ai/handoffs/LATEST.md` only as a checkpoint, never as a substitute for live GitHub state.

## Dynamic state

`.ai/CURRENT_STATE.md` is a convenience snapshot. Live GitHub, exact-head CI, and `docs/operation-parity-reconciliation.md` remain authoritative. Run:

`python variants/laravel-aiwmweb/.ai/scripts/refresh_context.py`

to reconstruct a fresh local summary. Use `--write` only when intentionally updating the committed snapshot.

## Definition of done

A task is not done because code exists. Use the task-specific skill and the repository operating system to prove implementation, deterministic tests, security/tenant isolation, parity/evidence when applicable, exact-head CI, integration, and handoff.
