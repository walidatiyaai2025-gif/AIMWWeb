# AIMWWeb Team Progress - 157

## Branch
fix/site-url-unique-constraint

## Completed
- FIX-URL-001: corrected the `AppLanguageService.Changed` delegate handler so the web project compiles.
- FIX-URL-002: changed site identity policy so URL reuse is allowed as separate site profiles.
- Removed application-level duplicate URL rejection from `SiteWebService`.
- Removed the unique site URL model index and added a database migration to drop the legacy unique index.
- Added explicit QA acceptance criteria for multiple profiles using the same WordPress URL.
- Bumped web build version to `155.107.0` for a deterministic release identity.

## Current rule
A WordPress URL is a connection target, not a unique profile. Re-registering the same URL creates a new `Site.Id` and preserves independent credentials/settings.

## Next Engineering Tasks
1. Build/rebuild the web solution against migration `20260808022000_AllowDuplicateSiteProfiles`.
2. Execute duplicate-profile QA on `/sites`.
3. Verify profile isolation for credentials, synchronization, SEO, execution jobs, and scheduled operations.
4. Continue with the next highest-priority defect without rewriting completed modules.

## Release validation
- Version: `155.107.0`
- Database migration: `20260808022000_AllowDuplicateSiteProfiles`
- Required test: register `https://notonlybook.com` twice with different profile data and confirm both records remain independent.
