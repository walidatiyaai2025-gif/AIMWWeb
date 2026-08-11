# UX-010 Visual Baseline Strategy

UX-010 uses two complementary regression layers so CI catches material layout regressions without making every anti-aliasing difference a release blocker.

## Layer 1 — structural visual gate (always enforced)

Every high-risk route is rendered in Chromium at the approved phone, tablet and desktop viewports. CI fails when the application creates page-level horizontal overflow, clips shared surfaces outside the viewport, loses the single shell `h1`, loses the main content landmark, or loses valid language/direction metadata. These checks are treated as approved material baselines immediately.

## Layer 2 — screenshot evidence and opt-in pixel approval

The same runs capture full-page PNG screenshots plus JSON geometry metrics. They are uploaded as the `ux-regression-evidence` artifact for 30 days. The committed registry at `tests/AIWordPressManager.UxTests/Baselines/approved-screenshot-sha256.json` is the approval switch for strict screenshot matching.

A screenshot becomes an **approved SHA-256 baseline** only after review. Add an entry whose key is `<route-key>--<viewport-key>.png` and whose value is the SHA-256 of the approved artifact. From that point forward, the UX test compares the newly captured image to that hash and fails CI on any pixel change. Removing or replacing an approved hash is therefore a deliberate baseline review action, not an automatic test update.

## Determinism controls

- Chromium is installed through the version pinned by the repository's central `Microsoft.Playwright` package.
- The application runs against an isolated temporary SQLite database and seeded local administrator.
- The browser fixes language to English for baseline capture and disables animations/transitions/caret rendering before measurement.
- RTL coverage is executed separately on selected high-risk pages so bidi behavior remains a hard structural gate without multiplying the pixel baseline set.
- Browser screenshots, geometry metrics and host logs are always uploaded, including failed runs.

## Baseline review rule

Never regenerate or replace approved screenshot hashes only to make CI green. First inspect the screenshot and metric artifacts, decide whether the visual change is intended, then update the approved hash in the same reviewed change that intentionally changes the UI.
