# AI WordPress Manager — UI/UX Master Plan

**Workstream:** Product Design / UI / UX  
**Priority:** Front-line delivery priority  
**Tracking:** GitHub Issues #46–#55  
**Current release:** `155.139.0` — UX-009

## Design principles

1. **One design system, no CSS islands.** Extend shared semantic tokens and reusable primitives before introducing page-specific styling.
2. **Theme-aware by default.** Every surface must work with the existing accent themes and dark/light modes without hard-coded brand-state assumptions.
3. **Bilingual parity.** Arabic RTL and English LTR use the same component architecture, logical CSS properties, equivalent hierarchy, and equivalent task flow.
4. **Accessible interaction.** Visible focus, semantic landmarks, descriptive control names, reduced-motion support, and minimum practical 44px interaction targets are baseline requirements.
5. **Responsive product, not desktop shrinkage.** Desktop, tablet, and mobile layouts must preserve hierarchy and actions without horizontal application breakage.
6. **Operational clarity.** Dense tables, jobs, approvals, AI actions, warnings, destructive operations, and offline/cache states must be easy to scan and hard to misread.
7. **Progressive disclosure.** Advanced controls and technical diagnostics stay available without overwhelming the primary task path.
8. **No fake state.** Loading, empty, success, warning, error, cached/offline, and partial-failure states must accurately reflect the application.
9. **Stable navigation.** Every production workspace must be reachable from primary navigation or command discovery; hidden URLs are not acceptable product IA.
10. **Regression-resistant delivery.** Shared shell changes build first; automated accessibility/visual gates follow in UX-010.

## Prioritized backlog

| ID | Priority | Status | Task | Dependency | GitHub / Release |
|---|---|---|---|---|---|
| UX-001 | **CRITICAL** | **Completed** | Premium design system & application shell foundation | — | #46 / `155.131.0` |
| UX-002 | **CRITICAL** | **Completed** | Navigation information architecture & discoverability | UX-001 | #47 / `155.132.0` |
| UX-003 | **CRITICAL** | **Completed** | Responsive mobile/tablet application shell | UX-001 | #48 / `155.133.0` |
| UX-004 | **CRITICAL** | **Completed** | WCAG AA accessibility, keyboard & focus UX | UX-001 | #49 / `155.134.0` |
| UX-005 | **HIGH** | **Completed** | Forms, validation, confirmations & destructive-action UX | UX-001/004 | #50 / `155.135.0` |
| UX-006 | **HIGH** | **Completed** | Data tables, filters, bulk actions & dense workspace UX | UX-001/003 | #51 / `155.136.0` |
| UX-007 | **HIGH** | **Completed** | Loading, empty, success, warning, offline & error states | UX-001 | #52 / `155.137.0` |
| UX-008 | **HIGH** | **Completed** | Arabic RTL / English LTR visual parity audit | UX-001/003 | #53 / `155.138.0` |
| UX-009 | **HIGH** | **Completed** | Page-by-page visual hierarchy & consistency audit | UX-001–008 | #54 / `155.139.0` |
| UX-010 | **HIGH** | **Next** | Visual and accessibility regression gates | UX-003/004/009 | #55 |

## UX-001 — delivered foundation

- Established the shared design-system token layer, shell spacing, elevations, radii, focus treatment, touch targets and motion.
- Refined sidebar, grouped navigation, topbar hierarchy, content canvas, skip link and primary landmarks.
- Preserved runtime accent selection and dark/light mode.
- Surfaced core AI, planner, execution, reporting and settings destinations in production navigation.

## UX-002 — delivered navigation architecture

- Established one navigation catalog for sidebar destinations, command search, current-location titles, descriptions, search aliases and role visibility.
- Added grouped command search and authorized recent/favorite destinations without duplicating route lists.
- Preserved tenant ownership and authorization as independent authoritative boundaries.

## UX-003 — delivered responsive shell

- Standardized the 1024px tablet/mobile off-canvas navigation contract and kept it independent from the desktop collapsed preference.
- Added safe-area/dynamic viewport behavior, responsive topbar condensation and overflow containment.
- Added mobile-card support to `AppDataGrid` and hardened shared dialog overflow/action behavior.
- Stable implementation head `a015a43485d0bfbfc68d7dcb7285259f15ea7bdb` passed Build #1413 and .NET Build Verification #1021 before release reconciliation.

## UX-004 — delivered accessibility, keyboard and focus hardening

- Added modal focus containment/restoration, route-context focus and announcement behavior, accessible shell landmarks and keyboard metadata.
- Hardened `AppDialog`, `AppButton`, `AppSearchBox` and `AppDataGrid` semantics while preserving source compatibility.
- Added visible-focus, contrast, reduced-motion and forced-colors final-layer protection.
- `AccessibilityContractTests` protect the shared contracts and exactly 50 completed UX-004 tasks.
- Implementation head `a6e409ff69ec784c3d6d2e331fd565d9d34cd177` passed .NET Build Verification #1030 with 279 passed, 0 failed, 0 skipped; Build #1422 completed Restore, Build and Test successfully.

## UX-005 — delivered forms, validation and destructive-action UX

- Standardized `AppFormField`, `AppValidationSummary`, `AppFormStatus`, `AppFormActions` and high-risk confirmations.
- Added form runtime/CSS for invalid focus, themed controls, practical targets, responsive/RTL/reduced-motion/forced-colors behavior.
- Adopted the contract in Account Profile, AI Provider Settings and Application User administration.
- `FormUxContractTests` protect the shared form/destructive-action contracts and exactly 100 completed tasks.
- Stable implementation head `ec5083c75d2ae16c4d819604cb5a660b9d532d18` passed Build #1427 and .NET Build Verification #1035 with 291 passed, 0 failed, 0 skipped.
- Test artifact #9070267846: 71,715 bytes; SHA-256 `a7b9de8e3c7fcec2d061b5a0e97cc77607d956ba9d437d9e29bb4bf92c49820c`.

## UX-006 — delivered dense data workspaces

- Expanded `AppDataGrid` with density, sticky headers, external filters, result-state separation, stable selection scope, paging/sort/export and mobile alternatives.
- Added `AppFilterBar`, `AppFilterChip` and hardened `AppBulkActionBar`.
- Adopted the shared dense-workspace contract in AI Usage.
- `DenseWorkspaceUxContractTests` protect the grid/filter/bulk/responsive contracts and exactly 100 completed tasks.
- Stable implementation head `fe507dff21b68b5f27f5e0a6ac7e27efe672958d` passed Build #1435 and .NET Build Verification #1043 with 300 passed, 0 failed, 0 skipped.
- Test artifact #9071148244: 73,890 bytes; SHA-256 `29bce85608cbf4d320621bc844357d2444475baf97e822c99766110f6c9e4204`.

## UX-007 — delivered application feedback states

- Added `AppStatePanel`, `AppStateBanner` and `AppSkeleton`; routed existing loading/empty wrappers through the shared contract.
- Standardized truthful loading, empty, success, warning, error, cached/stale, partial-failure and retry presentation.
- AI Usage retains the last successful snapshot during refresh and distinguishes partial/stale failures without hiding valid telemetry.
- `FeedbackStateUxContractTests` protect state semantics and exactly 100 completed tasks.
- Stable implementation head `7336f28e20f85c2ba63456e48488b56615048fc9` passed Build #1442 and .NET Build Verification #1050 with 310 passed, 0 failed, 0 skipped.
- Test artifact #9081891782: 76,983 bytes; SHA-256 `de74354c97c56e46b63d5bd8ca5411b3d99486fd97cbb3eebd10b86e24398772`.

## UX-008 — delivered RTL/LTR visual parity

- Added `AppBidiText`, directional icon intent/mirroring, optional badge bidi mode and dialog direction scoping.
- Added pre-paint language bootstrap and runtime root/body direction metadata synchronization.
- Added final `rtl-ltr-parity.css` using logical properties for shell, navigation, forms, dialogs, grids, feedback surfaces and technical metadata.
- Build & Release Notes isolates version, branch, commit, timestamps and technical paths while preserving copied build-report payload.
- `RtlLtrParityContractTests` protect direction/bootstrap/bidi/logical-layout contracts and exactly 100 completed tasks.
- Stable implementation head `a65d89edc7d273efa8b5fbb7df9461bf9adffd1c` passed Build #1448 and .NET Build Verification #1056 with 322 passed, 0 failed, 0 skipped before release reconciliation.
- Test artifact #9091043961: 80,064 bytes; SHA-256 `c882deb552b9b736123980803576fba6b56ff29b157a416ed9a0c6ae5f407969`.
- Browser-driven screenshot/visual-diff automation remains UX-010 scope.

## UX-009 — delivered page hierarchy & visual consistency

UX-009 removes page-level visual drift and is tracked as exactly 100 completed code tasks in `docs/UX_009_100_TASKS.md`.

### Shared composition hierarchy

- Added `AppPage` as the reusable page composition wrapper with page identity, narrow/standard/wide/fluid widths, compact/comfortable/spacious density and passthrough metadata.
- Standardized the semantic hierarchy as shell `h1` → page `AppToolbar` `h2` → `AppCard` / `AppSection` `h3` by default, with explicit bounded overrides where needed.
- `AppToolbar`, `AppCard` and `AppSection` now expose generated title/description relationships, normalized semantic tones and density metadata while preserving existing content/action regions.
- `AppStatCard` now supports accessible fallback naming, normalized tone/density, bidi-safe values and optional secondary metadata.

### Consistency layer

- Added `page-consistency.css` with shared page gaps/max widths, action clusters, metadata rows, two/three-column page grids, readable heading/copy rhythm and min-width containment.
- The consistency layer reconciles legacy panel/card selectors only inside pages that opt into `AppPage`, preventing a new global CSS island.
- Tablet/mobile action wrapping, density changes, reduced-motion and forced-colors behavior are explicit.
- Load order is `feedback-states.css` → `page-consistency.css` → `rtl-ltr-parity.css`, keeping UX-008 direction rules authoritative.

### Production adoption

- Dashboard now uses shared page, toolbar, card and progress hierarchy while preserving its live 15-second refresh timer, refresh lock and dashboard service calls.
- Build & Release Notes uses the shared hierarchy while retaining the literal `data-bidi-scope="build-info"` and technical bidi contracts protected by UX-008.
- Account Profile and Account Email Settings use shared page/section/state/card composition without changing password, encrypted SMTP or recipient service boundaries.
- System Health uses shared page/stat/section hierarchy without changing diagnostics, CSV or grid behavior.
- AI Prompt Templates uses shared page/toolbar/section/search/state composition while preserving append-only revisions and restore semantics.
- AI Center uses shared page/toolbar/stat/state hierarchy while preserving `Orchestrator.ExecuteAsync`, `AISuggestionContract.TryParse` and `ApprovalService.Submit` behavior.

### Regression coverage

- `PageConsistencyUxContractTests` protect page identity/width/density, heading hierarchy, toolbar/card/section/stat contracts, CSS/load order, production adoption and compatibility boundaries.
- The first implementation CI exposed one UX-008 static marker compatibility regression; the shared wrapper was improved with unmatched-attribute passthrough and the literal protected marker was restored instead of weakening the older test.
- Stable corrected implementation head `9584447199fd80daf16e305dc961b01103ca01e4` passed Build #1457 and .NET Build Verification #1065.
- Automated tests: 334 passed, 0 failed, 0 skipped.
- Test artifact #9091966297: 83,088 bytes; SHA-256 `0e1267e72c21dbe4f8bf873151e7d2f50311b08da3f07cb0800cdbb58df63fa6`.
- Build: 0 errors. One pre-existing CS8604 warning remains in `Services/PublicEntryRouting.cs`; UX-009 does not modify that service.
- Browser-driven screenshot/visual-diff and automated axe gates remain UX-010 scope.

## UX Definition of Done

A UX task is complete only when applicable requirements are met:
- Arabic and English states are equivalent.
- Dark/light and configurable accent themes remain usable.
- Keyboard focus is visible and interaction targets are practical.
- Mobile/tablet/desktop behavior is explicitly considered.
- Loading/empty/error implications are reviewed.
- Existing workflows remain functional; design work must not silently alter business logic.
- Restore/build/tests pass on the exact implementation head.
- Release/status documentation is reconciled before merge.

## Next task

**UX-010 — Visual and accessibility regression gates** is the next High-priority design task. It will add browser-driven visual comparison and automated accessibility checks on top of the shared shell, responsive, accessibility, forms, dense-workspace, feedback-state, RTL/LTR and page-consistency contracts delivered by UX-001 through UX-009.