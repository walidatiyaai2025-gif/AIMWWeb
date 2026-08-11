# UX-010 — 100/100 Visual & Accessibility Regression Gate Tasks

Exactly 100 completed code tasks are tracked below. UX-010 adds isolated browser quality gates and CI evidence without changing production authentication, tenant ownership, database schema, API contracts, AI routing, approval behavior, WordPress execution, or persistence semantics.

## CI foundation — 001–010
- [x] UX010-001 Create a dedicated Playwright UX test project outside the core solution test path.
- [x] UX010-002 Reference the centrally pinned Microsoft.Playwright package.
- [x] UX010-003 Keep xUnit as the browser regression runner.
- [x] UX010-004 Keep FluentAssertions available for diagnostic gate failures.
- [x] UX010-005 Add a dedicated UX Regression Gate GitHub Actions workflow.
- [x] UX010-006 Trigger the UX gate for pull requests targeting main.
- [x] UX010-007 Trigger the UX gate for pushes to main.
- [x] UX010-008 Add manual workflow dispatch for baseline review runs.
- [x] UX010-009 Add concurrency cancellation for superseded UX runs.
- [x] UX010-010 Bound the UX workflow with an explicit timeout.

## Isolated host and authentication — 011–020
- [x] UX010-011 Start the real ASP.NET Core web project from the browser fixture.
- [x] UX010-012 Reserve a free loopback TCP port per test run.
- [x] UX010-013 Run the test host only on loopback HTTP.
- [x] UX010-014 Use an isolated temporary SQLite database per UX run.
- [x] UX010-015 Mark setup complete only through child-process environment variables.
- [x] UX010-016 Isolate HOME/XDG application data from developer and runner profiles.
- [x] UX010-017 Wait on the real /health/live endpoint before opening Chromium.
- [x] UX010-018 Reuse the existing seeded Administrator account for browser authentication.
- [x] UX010-019 Persist authenticated browser storage state for repeatable route tests.
- [x] UX010-020 Capture child web-host output into the UX artifact bundle.

## Route smoke coverage — 021–030
- [x] UX010-021 Add anonymous smoke coverage for /welcome.
- [x] UX010-022 Add anonymous smoke coverage for /login.
- [x] UX010-023 Add authenticated smoke coverage for the dashboard route.
- [x] UX010-024 Add authenticated smoke coverage for /sites.
- [x] UX010-025 Add authenticated smoke coverage for /ai-center.
- [x] UX010-026 Add authenticated smoke coverage for /module/ai-usage.
- [x] UX010-027 Add administrator smoke coverage for /settings/ai-prompts.
- [x] UX010-028 Add authenticated smoke coverage for /approvals.
- [x] UX010-029 Add account smoke coverage for profile and email settings.
- [x] UX010-030 Add operational smoke coverage for system health and build/release pages.

## Accessibility browser audit — 031–040
- [x] UX010-031 Require a document language declaration on every audited page.
- [x] UX010-032 Require document direction to resolve to ltr or rtl.
- [x] UX010-033 Detect duplicate DOM ids.
- [x] UX010-034 Require alt attributes on rendered images.
- [x] UX010-035 Require accessible labels for visible inputs.
- [x] UX010-036 Require accessible labels for visible selects and textareas.
- [x] UX010-037 Require accessible names for visible buttons.
- [x] UX010-038 Require accessible names for visible links and role=button controls.
- [x] UX010-039 Reject positive tabindex ordering.
- [x] UX010-040 Reject nested interactive control patterns.

## Application shell semantics — 041–050
- [x] UX010-041 Require exactly one visible main landmark on authenticated application routes.
- [x] UX010-042 Require the shared #main-content anchor.
- [x] UX010-043 Require exactly one visible shell h1.
- [x] UX010-044 Require runtime direction metadata to survive route rendering.
- [x] UX010-045 Fail route tests on browser page exceptions.
- [x] UX010-046 Reject authenticated route redirects back to /login.
- [x] UX010-047 Reject authenticated route redirects to /setup.
- [x] UX010-048 Require successful server status for every route smoke case.
- [x] UX010-049 Add a keyboard Tab focus-entry smoke test.
- [x] UX010-050 Disable parallel browser collection execution for deterministic state.

## Responsive breakpoints — 051–060
- [x] UX010-051 Approve a 390x844 phone viewport gate.
- [x] UX010-052 Approve a 768x1024 tablet viewport gate.
- [x] UX010-053 Approve a 1440x900 desktop viewport gate.
- [x] UX010-054 Run dashboard visual checks at all approved viewports.
- [x] UX010-055 Run Sites visual checks at all approved viewports.
- [x] UX010-056 Run AI Center visual checks at all approved viewports.
- [x] UX010-057 Run Prompt Templates visual checks at all approved viewports.
- [x] UX010-058 Run Approvals visual checks at all approved viewports.
- [x] UX010-059 Run Account Profile visual checks at all approved viewports.
- [x] UX010-060 Run Account Email Settings visual checks at all approved viewports.

## Material visual contract — 061–070
- [x] UX010-061 Measure document viewport width in the browser.
- [x] UX010-062 Measure document scroll width in the browser.
- [x] UX010-063 Fail on application-level horizontal overflow beyond one pixel.
- [x] UX010-064 Measure the real main-content bounding box.
- [x] UX010-065 Fail when the main application content has zero width.
- [x] UX010-066 Detect shared AppToolbar/AppCard/AppSection/panel surfaces clipped outside the viewport.
- [x] UX010-067 Fail when shared audited surfaces leave the viewport.
- [x] UX010-068 Capture the rendered page title in visual metrics.
- [x] UX010-069 Capture rendered language and direction in visual metrics.
- [x] UX010-070 Persist geometry metrics as machine-readable JSON artifacts.

## Screenshot baseline system — 071–080
- [x] UX010-071 Disable animation duration during baseline capture.
- [x] UX010-072 Disable CSS transitions during baseline capture.
- [x] UX010-073 Hide caret rendering during baseline capture.
- [x] UX010-074 Wait for document fonts before measurement and screenshots.
- [x] UX010-075 Capture full-page PNGs for high-risk viewport cases.
- [x] UX010-076 Compute SHA-256 for every captured baseline candidate.
- [x] UX010-077 Persist screenshot hashes as CI artifacts.
- [x] UX010-078 Add a committed approved screenshot hash registry.
- [x] UX010-079 Fail CI when an approved screenshot hash changes.
- [x] UX010-080 Document intentional baseline approval/replacement rules.

## RTL and evidence — 081–090
- [x] UX010-081 Force a deterministic English language baseline before normal captures.
- [x] UX010-082 Add Arabic RTL rerun coverage for the dashboard.
- [x] UX010-083 Add Arabic RTL rerun coverage for AI Center.
- [x] UX010-084 Add Arabic RTL rerun coverage for Account Profile.
- [x] UX010-085 Re-run the structural visual gate after switching to RTL.
- [x] UX010-086 Re-run the accessibility browser audit after switching to RTL.
- [x] UX010-087 Upload screenshots even when the UX job fails.
- [x] UX010-088 Upload visual metric JSON even when the UX job fails.
- [x] UX010-089 Upload web-host logs even when the UX job fails.
- [x] UX010-090 Retain UX regression evidence for 30 days.

## Regression guards and compatibility — 091–100
- [x] UX010-091 Add static contract tests for the dedicated UX workflow.
- [x] UX010-092 Guard the Playwright project dependency contract.
- [x] UX010-093 Guard the approved phone/tablet/desktop viewport matrix.
- [x] UX010-094 Guard the required high-risk route catalog.
- [x] UX010-095 Guard the accessibility audit rule set.
- [x] UX010-096 Guard the structural visual material thresholds.
- [x] UX010-097 Guard the screenshot hash approval mechanism.
- [x] UX010-098 Guard that UX browser tests remain isolated from the core solution build path.
- [x] UX010-099 Guard the exact count of 100 completed UX-010 tasks.
- [x] UX010-100 Preserve production business/auth/tenant/persistence/API/AI/WordPress contracts while adding test-only gates.
