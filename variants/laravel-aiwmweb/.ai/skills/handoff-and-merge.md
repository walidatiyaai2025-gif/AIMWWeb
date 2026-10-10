# Skill: Handoff and Merge

## Trigger
Worker replacement, interruption recovery, PR ready for integration, post-merge continuation.

## Procedure
1. Re-fetch live composition, Issue, branch, PR, and exact-head CI.
2. Continue the same canonical task/branch/PR when recoverable.
3. Confirm the candidate is based on the permitted integration target and contains no unrelated boundary changes.
4. Confirm all required exact-head gates are green.
5. Merge normally; no force-push/history destruction.
6. Verify post-merge composition SHA and required post-merge gates.
7. Reconcile canonical parity/evidence if applicable.
8. Close/label the leaf according to repository operating system.
9. Advance/promote the next executable unit when the project loop requires it.
10. Write a concise checkpoint using `HANDOFF_TEMPLATE.md` only after live state is reconciled.

## Recovery
An old handoff is evidence, not truth. Live GitHub always wins.
