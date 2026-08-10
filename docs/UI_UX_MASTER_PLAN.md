# AI WordPress Manager — UI/UX Master Plan

**Workstream:** Product Design / UI / UX  
**Priority:** Front-line delivery priority  
**Tracking:** GitHub Issues #46–#55  
**Current release:** `155.132.0` — UX-002

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
| UX-003 | **CRITICAL** | **In Progress** | Responsive mobile/tablet application shell | UX-001 | #48 / PR #58 |
| UX-004 | **CRITICAL** | Planned | WCAG AA accessibility, keyboard & focus UX | UX-001 | #49 |
| UX-005 | **HIGH** | Planned | Forms, validation, confirmations & destructive-action UX | UX-001/004 | #50 |
| UX-006 | **HIGH** | Planned | Data tables, filters, bulk actions & dense workspace UX | UX-001/003 | #51 |
| UX-007 | **HIGH** | Planned | Loading, empty, success, warning, offline & error states | UX-001 | #52 |
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

## UX-003 — active responsive shell implementation

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

### Overflow and interaction safety
- Shell surfaces enforce `min-width: 0` and constrain accidental horizontal page overflow.
- Drawer navigation remains vertically scrollable while page-body scroll is locked.
- Practical touch targets remain at least 44px/46px for shell controls and navigation destinations.
- `AppDataGrid` now exposes an optional `MobileRowTemplate` so dense workspaces can switch from the desktop table to phone-card rows without duplicating filter, paging, sorting, selection, or export state.
- Existing data-grid consumers remain unchanged unless they opt into the mobile-card template; bounded component scrolling remains the safe fallback.
- Shared dialogs constrain wide content to the viewport, keep overflow inside the dialog body, allow long text to wrap, and let phone footer actions wrap instead of clipping.

### Regression coverage
- Static contract tests verify the shared 1024px breakpoint across CSS/runtime state logic.
- Tests protect independent desktop/mobile state, Razor-owned drawer controls, safe-area viewport support, dynamic viewport sizing, overflow containment, and landscape guards.
- Tests also protect the opt-in data-grid mobile-card contract and shared dialog overflow/action behavior.

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

## Next task after UX-003

**UX-004 — WCAG AA accessibility, keyboard & focus UX** remains the next Critical design task after UX-003 is validated and merged.
