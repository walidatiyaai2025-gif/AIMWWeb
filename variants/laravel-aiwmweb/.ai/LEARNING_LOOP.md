# Learning Loop

Goal: improve project execution over time without turning guesses into policy.

## Capture

After a verified task, ask:
- Did we discover a durable architectural/security rule?
- Did a failure reveal a recurring pattern?
- Did a skill miss a necessary validation step?
- Did an existing convention repeatedly prevent defects?

## Classify

- Architecture/security invariant -> `DECISIONS.md`
- Repeatable coding/testing rule -> `CONVENTIONS.md`
- Recurring hazard/root-cause pattern -> `KNOWN_ISSUES.md`
- Repeatable workflow improvement -> relevant `skills/*.md`
- Transient state -> Issue/PR/handoff only

## Evidence threshold

A durable entry requires at least one of:
- explicit owner/constitutional decision;
- merged PR with deterministic tests proving the behavior;
- exact-SHA CI evidence plus a proven root cause;
- repeated verified occurrences with references.

Do not promote:
- hypotheses;
- one-off temporary workarounds;
- stale chat statements;
- unmerged experiments;
- current PR status.

## Entry format

Every new durable lesson should include:
- ID/name;
- decision/rule;
- why it exists;
- evidence: Issue/PR/SHA/test;
- scope;
- supersedes/superseded-by when applicable.

## Skill refinement rule

Change a skill when the lesson changes *how future tasks should be executed*, not merely because one task had a unique detail. Keep skills short, procedural, and testable.
