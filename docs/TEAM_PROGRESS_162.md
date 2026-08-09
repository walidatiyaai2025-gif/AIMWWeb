# AIMWWeb Team Progress - 162

## WELCOME-UX-009 First-run landing and build-version rendering

Status: Implemented - CI validation pending

## Scope
- Fix Razor rendering of version labels that used the ambiguous `v@Build.Version` form.
- Add a polished bilingual first-run landing page after database setup and first login.
- Keep the existing dashboard and operational navigation intact for established users.

## Implementation
- Replaced ambiguous version labels with explicit Razor expressions such as `v@(Build.Version)`.
- Corrected version rendering in the global top bar, sidebar build link, current-build badge and release-history labels.
- Added `/welcome` with responsive RTL/LTR UI, dark/light theme support and existing accent-theme integration.
- Added product overview, operating-flow map, platform capability cards, guided first steps and clear calls to action.
- Added Welcome to the main navigation and command palette.
- Changed the no-site login destination from `/sites/connect` to `/welcome`, so a newly configured installation introduces the product before asking the administrator to connect a site.
- Preserved the existing last-page redirect behavior for users who already own sites.
- Added a test contract for the first-run landing path.
- Bumped the web build to `155.109.0`.

## UX rule
A newly configured installation should orient the administrator first, then let them deliberately start site onboarding. The landing page must remain reachable later from the Overview navigation.

## Validation gate
1. Build the full solution.
2. Run automated tests.
3. Verify `/welcome` renders in English and Arabic.
4. Verify dark/light and all accent themes retain readable contrast.
5. Verify a user with zero owned sites signs in to `/welcome`.
6. Verify an established user with sites still resumes the requested/last safe page.
7. Verify all visible `v...` build labels render the actual version and never the literal `@Build.Version` token.
8. Merge only after both GitHub Actions workflows are green.
