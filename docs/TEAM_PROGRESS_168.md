# TEAM_PROGRESS_168

## LANDING-UX-016 Premium landing redesign

Status: Implemented - CI validation pending

### Problem
The public `/welcome` experience had drifted into an internal-dashboard style page. The hero was visually oversized, anonymous navigation exposed protected product modules, the decorative orbit graphic did not communicate the actual product, and repeated dark cards created weak hierarchy.

### Implemented
- Rebuilt the landing information architecture around the public product story instead of internal modules.
- Replaced the previous orbit illustration with a responsive in-product command-center preview built from native markup/CSS.
- Simplified the public header to Platform, Capabilities, Workflow, and Control anchors.
- Preserved authenticated Dashboard access and protected CTAs through the existing `ProtectedHref` behavior.
- Rewrote the hero copy to be shorter, product-specific, and less promotional.
- Added a concise platform proof row for multi-site operation, bilingual UI, auditable execution, and offline snapshots.
- Added an authenticated live-metrics strip; anonymous visitors no longer see fake/empty live metric cards.
- Reorganized product capabilities into six consistent workspaces: sites, content, SEO, AI, automation, and backup/diagnostics.
- Added a clear Observe -> Review -> Execute operating model.
- Added a control/safety section describing independent site identities, isolated credentials, persistent synchronization history, and auditable execution.
- Rebuilt responsive behavior for desktop, tablet, mobile, RTL and LTR.
- Added reduced-motion support.
- Bumped the web version to `155.115.0`.

### Compatibility
- `/welcome` remains anonymous and continues using `LandingLayout`.
- Authentication detection and live dashboard loading remain unchanged in behavior.
- Anonymous protected links still route through `/login?returnUrl=...`.
- Existing language persistence through `window.appLanguage` remains intact.
- No database, authentication schema, WordPress API, or execution architecture changes.

### Validation / merge gate
1. Full solution build must pass, including Razor compilation.
2. Full automated test suite must pass.
3. Confirm `/welcome` renders without protected-data calls for anonymous users.
4. Confirm authenticated users still receive live site/content/job/sync values.
5. Confirm Arabic RTL and English LTR layout order and alignment.
6. Confirm responsive layout at desktop, tablet, and narrow mobile widths.
7. Merge only after required GitHub Actions workflows are green.

### Next
Resume `SITE-BULK-015` after the landing redesign is merged unless a higher-priority user-facing defect is reported.
