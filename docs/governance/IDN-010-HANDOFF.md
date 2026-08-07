# IDN-010 — Account lockout and failed sign-in tracking

- Phase: 2 — Identity and tenant isolation
- Branch: `feature/idn-010-lockout-hardening`
- Version target: `155.102.0`
- Status during validation: in progress

## Implemented
- Five failed sign-in attempts retain the existing fifteen-minute account lockout policy.
- Added an explicit domain-level lockout-state check.
- Expired lockout state is cleared before counting a subsequent failed attempt, so a single failure after expiry no longer immediately re-locks the account.
- Successful sign-in still clears failed-attempt and lockout state.
- Existing login auditing remains intact.

## Verification required
- GitHub Actions Build.
- GitHub Actions .NET Build Verification including automated tests.

## Registry handoff
After CI succeeds, update `IDN-010` in `development-status.json` from `planned` to `completed`, version `155.102.0`, with notes referencing the verified five-attempt / fifteen-minute policy and post-expiry reset behavior.
