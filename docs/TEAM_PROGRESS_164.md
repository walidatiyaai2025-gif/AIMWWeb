# AIMWWeb Team Progress - 164

## WELCOME-UX-011 Public anonymous landing

Status: Implemented - CI validation pending

## Scope
- Make `/welcome` publicly accessible without authentication.
- Keep the rest of the application protected by the existing authenticated fallback policy.
- Prevent anonymous visitors from loading user-scoped dashboard data.

## Implementation
- Added explicit `AllowAnonymous` metadata to the `Welcome` routable component.
- Preserved the application-wide authenticated fallback policy for operational pages and APIs.
- Added authentication-aware landing behavior: signed-in users can load live `DashboardLiveService` metrics, while anonymous visitors see safe placeholders only.
- Added a visible Sign in action for anonymous visitors and Dashboard action for authenticated users.
- Protected product/module links route anonymous visitors through the login entry point rather than attempting to read private state.
- Added regression tests that assert the Welcome component remains anonymous and does not gain an `Authorize` attribute.
- Bumped the web version to `155.111.0`.

## Security rule
The public landing may expose product copy, build version and navigation entry points, but it must never query or render per-user WordPress sites, content counts, jobs, synchronization state or other tenant-scoped data unless the current principal is authenticated.

## Validation gate
1. Build the full solution.
2. Run all automated tests.
3. Confirm `/welcome` carries `AllowAnonymous` metadata under the global fallback authorization policy.
4. Confirm anonymous rendering does not call `DashboardLiveService`.
5. Confirm authenticated rendering still shows live metric data.
6. Merge only after all GitHub Actions checks are green.
