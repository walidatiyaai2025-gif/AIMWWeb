# Skill: CI Repair

## Trigger
Any required exact-head workflow is red, cancelled, action-required, or inconsistent with local focused tests.

## Procedure
1. Stay on the existing task branch/PR.
2. Identify the exact failing job and first failing step/test on the exact head.
3. Reproduce the same command/environment locally or reason from captured artifact/log evidence.
4. Classify: product defect, test defect, generated evidence drift, dependency/environment issue, or governance mismatch.
5. Fix the root cause without weakening assertions/security/parity gates.
6. Re-run the smallest failing test first, then the full required suite.
7. Push a new exact head and require all mandated workflows green.
8. Do not merge using green checks from an older SHA.
9. If CI reveals a reusable failure pattern, promote it through `LEARNING_LOOP.md`.

## Forbidden
Retry/v2/final branches for the same active unit, skipped tests to gain green, hand-edited parity counts, or force merge.
