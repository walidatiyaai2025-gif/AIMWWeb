# TEAM_PROGRESS_160

## SITE-UI-007 Site profile identity UX

Status: Implemented - CI validation pending

Completed:
- Updated `/sites` helper copy in Arabic and English to reflect the current product rule: normalized WordPress URLs may be reused across independent site profiles.
- Added a visible short `Profile ID` to every site card so two profiles pointing to the same URL are distinguishable.
- Added a credential-scope indicator that makes it clear credentials belong to the individual profile without exposing usernames or secrets.
- Removed the obsolete duplicate-site exception translation from the page so migration/constraint failures surface with their real actionable message instead of incorrectly claiming the URL is already registered.

Compatibility:
- No change to the `Site.Id` identity model.
- No credential data is exposed by the list page.
- Existing URL normalization, ownership filtering, soft-delete behavior, and connection actions remain unchanged.

QA / merge gate:
1. Build and test PR #15 after commit `ffb40680850f988435e1767685db86f2b7358102`.
2. Register the same normalized URL twice and verify both cards show different profile IDs.
3. Confirm Arabic and English helper copy both state that duplicate URLs are allowed as independent profiles.
4. Confirm a stale database uniqueness constraint surfaces the migration guidance rather than a duplicate-site message.
5. Confirm edit/retest/delete actions continue targeting the selected `Site.Id`.

Next:
- If CI is green, complete interactive duplicate-profile QA and merge PR #15.
- Then continue from the next highest-priority roadmap defect without rewriting completed modules.
