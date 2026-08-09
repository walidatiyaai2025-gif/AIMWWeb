# AIMWWeb Team Progress - 165

## PUBLIC-ENTRY-012 Public root entry and login return flow

Status: Implemented - CI validation pending

## Scope
- Keep `/welcome` publicly accessible.
- Make anonymous requests to `/` enter the public landing experience instead of the authenticated dashboard challenge.
- Preserve the existing `/` dashboard behavior for authenticated users.
- Preserve safe `returnUrl` destinations through the login form so public landing CTAs resume at the intended protected module after successful sign-in.
- Make the public landing the first page shown after successful database setup.

## Implementation
- Added `PublicEntryRouting` and middleware after authentication but before authorization: anonymous GET/HEAD `/` redirects to `/welcome`; authenticated `/` continues to the existing dashboard component.
- Kept setup enforcement earlier in the pipeline, so unconfigured installations still enter `/setup` first.
- Changed successful setup and completed-setup revisits from `/login` to `/welcome`.
- Preserved a validated local `returnUrl` in the login form and now pass it into `LocalAuthenticationService.SignInAsync`.
- Updated first-run login behavior so a safe explicit CTA destination is honored; direct sign-in with no requested target still lands on `/welcome` when the user has no sites.
- Rejected external, protocol-relative, auth-loop and malformed redirect targets through the existing `ResolveRedirectPath` safety rules.
- Improved the login view to show authentication errors without dropping the continuation target and added a path back to the public product overview.
- Logout now returns to `/welcome`.
- Added routing and first-run redirect regression tests.
- Bumped the web version to `155.112.0`.

## Security constraints
- The global authenticated fallback policy remains unchanged for operational pages and APIs.
- Anonymous root routing occurs only for GET/HEAD `/` after authentication has populated the principal.
- Tenant-scoped data remains unavailable to anonymous users.
- Setup-incomplete instances still redirect to `/setup` before public-root routing.

## Validation gate
1. Full solution build.
2. Automated tests.
3. Anonymous GET/HEAD `/` redirects to `/welcome` after setup is complete.
4. Authenticated GET `/` continues to the dashboard.
5. Login preserves local `returnUrl` through POST and rejects unsafe external destinations.
6. Direct first-run login without a target remains `/welcome`; explicit safe landing CTAs resume their intended target.
7. Merge only after required GitHub Actions checks are green.
