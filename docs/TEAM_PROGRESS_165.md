# AIMWWeb Team Progress - 165

## PUBLIC-ENTRY-012 Public root entry and login return flow

Status: IN PROGRESS

## Scope
- Keep `/welcome` publicly accessible.
- Make an anonymous GET to `/` enter the public landing experience instead of the authenticated dashboard challenge.
- Preserve the existing `/` dashboard behavior for authenticated users.
- Preserve safe `returnUrl` destinations through the login form so public landing CTAs resume at the intended protected module after successful sign-in.

## Security constraints
- Do not relax the global authenticated fallback policy for operational pages or APIs.
- Do not allow external/open redirects; continue to use `LocalAuthenticationService.ResolveRedirectPath` for final redirect validation.
- Setup-incomplete instances must still redirect to `/setup` before public-root routing.

## Validation gate
1. Full solution build.
2. Automated tests.
3. Anonymous GET `/` redirects to `/welcome` after setup is complete.
4. Authenticated GET `/` continues to the dashboard.
5. Login preserves local `returnUrl` through POST and rejects unsafe external destinations.
6. Merge only after required GitHub Actions checks are green.
