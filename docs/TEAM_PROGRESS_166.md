# AIMWWeb Team Progress - 166

## ONBOARD-UX-013 Site onboarding profile consistency

Status: IMPLEMENTED - CI VALIDATION PENDING

## Problem found
The public landing/login flow now correctly resumes first-site onboarding at `/sites/connect`, but the onboarding screen still contained two inconsistencies with the current site-profile model:

1. Step 1 said normalized WordPress URLs were blocked as duplicates, while FIX-URL-002 intentionally allows the same WordPress URL to be reused by independent site profiles.
2. If a site profile was created and the WordPress connection test then failed, the user could go back and edit the profile name/URL. A retry reused the existing `Site.Id` but did not persist those profile edits before retesting.

## Implementation
- Updated Arabic and English onboarding guidance to explain that one WordPress URL can be reused by independent profiles with separate credentials/settings.
- Added `SiteOnboardingProfileFlow` to make create-vs-update retry behavior explicit and testable.
- First save creates a new site profile.
- Retry for an already-created profile updates that same `Site.Id` with the current name/URL before testing credentials again.
- No duplicate profile is created merely because the user retries a failed connection test.
- Existing site ownership enforcement and encrypted credential handling remain unchanged.
- Added automated tests covering create and existing-profile update paths.
- Bumped the web version to `155.113.0`.

## Validation gate
1. Restore solution.
2. Build full solution.
3. Run automated tests.
4. Verify first onboarding save uses create path.
5. Verify failed-test retry updates the same profile instead of silently retaining old name/URL or creating another profile.
6. Verify duplicate-URL guidance matches the current reusable-profile model in Arabic and English.
7. Merge only after required GitHub Actions checks are green.

Refs #3.
