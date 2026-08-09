# AIMWWeb Team Progress - 158

## Branch
fix/site-url-unique-constraint

## Completed
- FIX-URL-004: `SiteManagementService.CreateAsync` now always creates a new `Site` profile.
- Re-registration of an active URL no longer updates/reuses the previous profile.
- Re-registration after soft-delete creates a new `Site.Id` and preserves the deleted profile for history.
- Credentials remain isolated by `SiteCredential.SiteId`.
- TEST-URL-005: replaced the old soft-delete restore expectation with explicit tests for independent duplicate profiles and post-delete re-registration.
- Existing build/test pipeline remains the gate for these changes.

## Important product rule
`SiteUrl` is a connection target. `Site.Id` is the profile identity.

## Known UI follow-up
The `/sites` form contains an outdated helper message saying duplicate sites are blocked. It must be changed to explain that the URL is normalized and reusable for multiple profiles.

## Next Engineering Tasks
1. Update the `/sites` helper text in Arabic and English.
2. Add an explicit profile identifier/credential indicator to the site card so duplicate URLs are visually distinguishable.
3. Validate duplicate-profile behavior through the interactive Sites page, not only persistence tests.
4. Continue from the next highest-priority defect after CI passes.
