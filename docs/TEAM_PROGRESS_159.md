# TEAM_PROGRESS_159

## SITE-UI-007 Site profile identity UX

Status: In Progress

Completed:
- Business model now supports independent site profiles.
- Duplicate URL handling moved from URL uniqueness to profile identity.
- CI validation running on PR #15.

Next implementation:
- Update Sites page helper text to explain profile-based registration.
- Add profile identity indicator in site cards.
- Add credential/profile visibility hints without exposing secrets.

QA:
- Verify multiple profiles with the same WordPress URL display independently.
- Verify delete/recreate workflow.
