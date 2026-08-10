# AI WordPress Manager — UI/UX Master Plan

**Workstream:** Product Design / UI / UX  
**Priority:** Front-line delivery priority  
**Tracking:** GitHub Issues #46–#55  
**Current release:** `155.136.0` — UX-006

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
| UX-007 | **HIGH** | **Next** | Loading, empty, success, warning, offline & error states | UX-001 | #52 |
| UX-008 | **HIGH** | Planned | Arabic RTL / English LTR visual parity audit | UX-001/003 | #53 |
| UX-009 | **HIGH** | Planned | Page-by-page visual hierarchy & consistency audit | UX-001–008 | #54 |
| UX-010 | **HIGH** | Planned | Visual and accessibility regression gates | UX-003/004/009 | #55 |

## UX-001 — delivered foundation

UX-001 established the shared shell contract that later design tasks build on.

### Design system foundation
- Extended the existing `--ui-*` token layer with shell semantics instead of creating a competing token system.
- Standardized shell width, content gutters, elevations, radii, focus treatment, touch targets, and motion.
- Preserved existing runtime accent selection and dark/light mode.

### Application shell
- Refined sidebar, brand block, grouped navigation, active states, topbar hierarchy, and content canvas.
- Removed stale hard-coded branch/version decoration from CSS; build identity is rendered only from `BuildInformationService`.
- Made primary landmarks and interactive controls screen-reader identifiable.
- Added a keyboard skip link to the application content.

### Discoverability baseline
- Surfaced AI Center, AI Usage & Cost, Content Planner, Prompt Templates, and provider configuration in the AI workspace.
- Expanded command discovery for approvals, schedules, logs/errors, backup/restore, and current AI routes.
- Corrected page-title route matching so `/` cannot falsely identify unrelated routes as Dashboard.

### Responsive baseline
- Kept desktop navigation stable, tablet topbar compact, and mobile navigation off-canvas.
- Resolved conflicting legacy mobile navigation rules so the off-canvas sidebar remains vertically usable.
- Added practical touch targets, responsive content gutters, reduced-motion behavior, and tablet language-control treatment.

## UX-002 — delivered navigation architecture

UX-002 removes navigation drift by treating destinations as product information architecture rather than duplicated UI strings.

### Navigation catalog
- Established one server-side catalog for primary navigation, command search, current-location titles, localized descriptions, search aliases, and role visibility.
- Administrator-only destinations are discoverable only to authenticated administrators.
- Automation Center, Notification Inbox, account destinations, and administrative settings are represented without hidden production URLs.

### Search and recent access
- Command search matches page names, capability descriptions, keywords, and routes.
- Results are grouped by workspace with localized context and route hints.
- Recent/Favorites receives the authorized catalog through JS interop instead of maintaining a stale client-only route list.
- `Ctrl+Shift+P` remains available; desktop/tablet uses topbar access while the floating launcher remains for mobile.

### Workflow shortcuts
- Quick Actions now focus on the highest-value site, content, AI, planner, automation, execution, synchronization, SEO, notification, and reporting workflows.
- Tenant ownership and authorization remain independent authoritative boundaries; navigation changes do not change data access.

### Regression coverage
- Unique routes and group keys are enforced by tests.
- Localized catalog metadata is verified.
- Important production destinations, most-specific route matching, admin visibility, root-route safety, and capability-keyword search are covered.
- Implementation and self-review heads passed both Build and .NET Build Verification before release reconciliation.

## UX-003 — delivered responsive shell

UX-003 turns the UX-001 shell into a production responsive application rather than a compressed desktop layout.

### Responsive drawer contract
- Tablet and mobile widths up to 1024px use one off-canvas navigation model.
- Responsive drawer state is ephemeral and independent from the persisted desktop collapsed-sidebar preference.
- Entering tablet/mobile mode starts with navigation closed so content is never unexpectedly covered.
- Route selection, backdrop click, explicit close control, and Escape close the drawer.
- Drawer close/backdrop controls are Razor-owned elements inside `MainLayout`; JavaScript does not inject nodes into Blazor-managed shell DOM.
- Resize and orientation changes reconcile drawer state without corrupting desktop preference.

### Safe-area and viewport behavior
- The application viewport opts into `viewport-fit=cover`.
- Shared shell spacing uses device safe-area insets for topbar, drawer, popovers, command search, and bottom actions.
- Dynamic viewport units (`dvh`) keep drawers and overlays aligned when browser chrome changes.

### Tablet and phone condensation
- The topbar collapses secondary context before primary actions.
- Search, language, account, appearance, theme, and recent controls progressively condense by available width instead of wrapping unpredictably.
- The mobile floating Recent/Favorites launcher remains available when the topbar version is hidden.
- Landscape-short layouts prioritize navigation and content over decorative/footer information.

### Overflow and dense-workspace safety
- Shell surfaces enforce `min-width: 0` and constrain accidental horizontal page overflow.
- Drawer navigation remains vertically scrollable while page-body scroll is locked.
- Practical touch targets remain at least 44px/46px for shell controls and navigation destinations.
- `AppDataGrid` exposes an optional `MobileRowTemplate` so dense workspaces can switch from the desktop table to phone-card rows without duplicating filter, paging, sorting, selection, or export state.
- Existing data-grid consumers remain unchanged unless they opt into the mobile-card template; bounded component scrolling remains the safe fallback.
- Shared dialogs constrain wide content to the viewport, keep overflow inside the dialog body, allow long text to wrap, and let phone footer actions wrap instead of clipping.

### Regression coverage
- Static contract tests verify the shared 1024px breakpoint across CSS/runtime state logic.
- Tests protect independent desktop/mobile state, Razor-owned drawer controls, safe-area viewport support, dynamic viewport sizing, overflow containment, and landscape guards.
- Tests also protect the opt-in data-grid mobile-card contract and shared dialog overflow/action behavior.
- Stable implementation head `a015a43485d0bfbfc68d7dcb7285259f15ea7bdb` passed Build #1413 and .NET Build Verification #1021 before release reconciliation.

## UX-004 — delivered accessibility, keyboard and focus hardening

UX-004 converts accessibility from a collection of local affordances into a shared application contract. The implementation is tracked as exactly 50 completed tasks in `docs/UX_004_50_TASKS.md`.

### Keyboard and modal focus contract
- A shared runtime discovers visible modal dialogs and moves focus to the preferred or first usable control.
- Tab and Shift+Tab remain contained inside the active modal; stray focus is redirected back into it.
- Modal close restores the original opener when it remains available and background scrolling is locked while modal interaction is active.
- Shared Escape behavior targets explicitly marked close controls without changing domain workflows.

### Landmarks, page context and announcements
- Route content is the actual `main` landmark and is programmatically associated with the shared page `h1`.
- Breadcrumbs use a navigation landmark and expose the current page.
- Client-side page-title changes move focus to `#main-content` and announce the new context through a shared polite live region.
- Command, recent/favorites and accessibility shortcuts expose keyboard metadata to assistive technology.

### Shared component semantics
- `AppDialog` provides unique IDs, programmatic title/description relationships, configurable close names and focusable dialog containers.
- `AppButton` provides accessible-name fallback, busy state, optional pressed state and correct disabled-link behavior.
- `AppSearchBox` provides configurable clear naming, busy-state announcements and autocomplete control.
- `AppDataGrid` provides table naming, filtered row count, accessible selection labels, pagination landmarks, labelled page-size controls and polite state updates.
- All new component parameters are optional/defaulted, preserving source compatibility for existing call sites.

### Display preferences and contrast
- Accessibility settings now expose dialog/toggle semantics, preserve keyboard focus after preference rerenders and restore focus to the trigger on close.
- Shared final-layer CSS enforces visible keyboard focus, preserves the semantic page `h1` visual hierarchy, honors OS and user reduced-motion preferences, supports higher contrast/forced colors, provides non-color selected-row indication and keeps common interaction targets at 44px or larger.

### Regression coverage
- `AccessibilityContractTests` protect modal runtime behavior, shell landmarks, shared dialog/button/search/grid semantics, accessibility settings, host loading order and accessibility CSS contracts.
- A regression guard asserts exactly 50 completed UX-004 implementation tasks.
- Implementation head `a6e409ff69ec784c3d6d2e331fd565d9d34cd177` passed .NET Build Verification #1030 with 279 passed, 0 failed, 0 skipped; Build #1422 completed Restore, Build and Test successfully before release reconciliation.
- Browser-driven axe/visual accessibility automation remains scoped to UX-010 and is not falsely claimed as part of UX-004.

## UX-005 — delivered forms, validation and destructive-action UX

UX-005 standardizes the form interaction contract and is tracked as exactly 100 completed implementation tasks in `docs/UX_005_100_TASKS.md`.

### Shared form primitives
- `AppFormField` standardizes label association, required/optional wording, helper copy, constraints, field-level errors, and deterministic ARIA relationship IDs.
- `AppValidationSummary` provides a focusable assertive summary for preflight validation failures.
- `AppFormStatus` separates assertive errors from polite success/information outcomes and can include recovery guidance.
- `AppFormActions` standardizes save/cancel busy behavior, prevents double activation, and supports contextual and unsaved-state messaging.

### Destructive confirmations
- `AppConfirmDialog` now supports impact and recovery guidance without breaking existing call sites.
- High-risk actions can require typed confirmation before the confirm action becomes available.
- Typed confirmation state resets with dialog lifecycle and remains blocked while work is busy.
- Confirmation copy, action names, close labels, and recovery guidance are localized-ready.

### Form runtime and visual system
- `form-ux.js` discovers invalid controls, reflects native invalid state, and focuses newly rendered validation summaries after Blazor rerenders.
- `forms-ux.css` standardizes themed controls, invalid treatment, 44px practical targets, responsive action layouts, RTL logical properties, reduced motion, and forced-colors behavior.
- The form layer loads after UX-004 accessibility hardening so focus and accessibility contracts remain authoritative.

### High-risk adoption
- Account Profile performs service-aligned password preflight validation before the existing account service call and exposes field-specific accessible errors.
- AI Provider Settings validates all model names before persistence and requires typing `REMOVE` before stored encrypted keys can be deleted.
- Application User administration validates create/edit and password-reset forms, confirms account disable impact/recovery, and requires the selected username before administrator password reset.
- Existing application services remain the authoritative security and persistence boundary.

### Regression coverage
- `FormUxContractTests` protect shared form primitives, destructive confirmations, form runtime, CSS, host loading order, and the three high-risk adoption points.
- A regression guard asserts exactly 100 completed UX-005 implementation tasks.
- Stable implementation head `ec5083c75d2ae16c4d819604cb5a660b9d532d18` passed Build #1427 and .NET Build Verification #1035 with 291 passed, 0 failed, 0 skipped before release reconciliation.
- Test artifact #9070267846 is 71,715 bytes with SHA-256 `a7b9de8e3c7fcec2d061b5a0e97cc77607d956ba9d437d9e29bb4bf92c49820c`.

## UX-006 — delivered dense data workspaces

UX-006 standardizes dense operational data interaction and is tracked as exactly 100 completed implementation tasks in `docs/UX_006_100_TASKS.md`.

### Shared data-grid contract
- `AppDataGrid` now supports compact/comfortable/spacious density, optional striping, sticky headers, focusable scroll viewports and rows, explicit captions, row-state metadata, and non-color selection/state indicators.
- External predicates combine with grid search without duplicating source collections; active-filter count, no-results recovery, clear-all behavior, pagination reset, sort direction, and filtered/sorted CSV export are explicit shared contracts.
- Selection supports visible-page selection, select-all-filtered scope, hidden-selected reporting, stale-key reconciliation, and optional integrated bulk actions.
- Empty data and filtered no-results are distinct states rather than one ambiguous blank-table path.

### Filters and bulk actions
- `AppFilterBar` provides a reusable busy-aware search/filter region with active-filter state, applied-filter chips and clear-all recovery.
- `AppFilterChip` uses real keyboard-accessible remove buttons with explicit accessible names.
- `AppBulkActionBar` exposes region/busy semantics, sticky safe-area placement, scope guidance, optional dangerous treatment, secondary actions, and a labelled clear-selection path.
- Shared CSS preserves RTL/LTR directionality, mobile card fallbacks, bounded overflow, practical touch targets, reduced motion, and forced-colors behavior.

### Production adoption
- AI Usage now uses `AppFilterBar`/`AppFilterChip` for account-scoped site filtering.
- Recent AI calls now render through `AppDataGrid` with search, CSV export, compact density, sticky/striped scanability, success/error row states, localized pagination, and mobile cards.
- Usage-service identity and persistence boundaries remain unchanged; UX-006 only changes presentation and query-state interaction.

### Regression coverage
- `DenseWorkspaceUxContractTests` protect grid hierarchy, filtering, selection scope, paging/sort/export state, shared filter controls, bulk actions, responsive/RTL/forced-color CSS, AI Usage adoption, and the exact 100-task manifest.
- Stable implementation head `fe507dff21b68b5f27f5e0a6ac7e27efe672958d` passed Build #1435 and .NET Build Verification #1043 with 300 passed, 0 failed, 0 skipped before release reconciliation.
- Test artifact #9071148244 is 73,890 bytes with SHA-256 `29bce85608cbf4d320621bc844357d2444475baf97e822c99766110f6c9e4204`.
- One pre-existing CS8604 warning remains in `Services/PublicEntryRouting.cs`; UX-006 does not modify that service.

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

**UX-007 — Loading, empty, success, warning, offline & error states** is the next High-priority design task and builds on the shared shell, accessibility, form, and dense-workspace contracts delivered by UX-001 through UX-006.