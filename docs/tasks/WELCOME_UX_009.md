# WELCOME-UX-009 - First-run product landing

## Goal
Give a newly configured administrator a clear product orientation before site onboarding, while fixing build-version labels that can render as literal Razor text.

## Acceptance criteria
- `/welcome` is authenticated and available from Overview navigation.
- A successful first login with zero owned sites lands on `/welcome`.
- The page explains Sites, Content, SEO, AI, approvals/execution, automation, reporting and health capabilities.
- Primary actions lead to first-site onboarding and the dashboard.
- The page supports Arabic/English, RTL/LTR, responsive layouts, dark/light modes and all supported accent themes.
- Established users with owned sites keep safe requested/last-page redirect behavior.
- Version labels use explicit Razor expressions and never render `@Build.Version` literally.
- Build version is `155.109.0`.
- Full build and automated test workflows must be green before merge.
