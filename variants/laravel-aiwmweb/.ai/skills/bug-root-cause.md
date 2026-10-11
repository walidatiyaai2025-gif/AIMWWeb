# Skill: Bug Root Cause

## Trigger
A user-visible defect, regression, incorrect state, failed mutation, or inconsistent behavior.

## Procedure
1. Reproduce or establish deterministic failing evidence before changing code.
2. Trace from UI/request through route, tenant/auth, service, persistence/external boundary, and reread.
3. Identify the first invariant that becomes false; fix that cause rather than masking the symptom.
4. Add a regression test that fails for the original cause.
5. Check adjacent paths sharing the same abstraction.
6. Preserve truthful failure behavior and tenant isolation.
7. Run focused tests, then full required suites and exact-head CI.
8. If the same root-cause pattern is recurring, add it to `KNOWN_ISSUES.md` or refine a skill with evidence.

## Anti-patterns
- catch-and-return-success;
- hard-coded state to satisfy UI;
- broad retries around destructive calls;
- weakening tests to match broken behavior;
- creating a new branch just for CI repair of an active task.
