# AI WordPress Manager — UI/UX Master Plan

**Workstream:** Product Design / UI / UX  
**Priority:** Front-line delivery priority  
**Tracking:** GitHub Issues #46–#55  
**Current release:** `155.131.0` — UX-001

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
| UX-002 | **CRITICAL** | **In Progress** | Navigation information architecture & discoverability | UX-001 | #47 |
| UX-003 | **CRITICAL** | Planned | Responsive mobile/tablet application shell | UX-001 | #48 |
| UX-004 | **CRITICAL** | Planned | WCAG AA accessibility, keyboard & focus UX | UX-001 | #49 |
| UX-005 | **HIGH** | Planned | Forms, validation, confirmations & destructive-action UX | UX-001/004 | #50 |
| UX-006 | **HIGH** | Planned | Data tables, filters, bulk actions & dense workspace UX | UX-001/003 | #51 |
| UX-007 | **HIGH** | Planned | Loading, empty, success, warning, offline & error states | UX-001 | #52 |
| UX-008 | **HIGH** | Planned | Arabic RTL / English LTR visual parity audit | UX-001/003 | #53 |
| UX-009 | **HIGH** | Planned | Page-by-page visual hierarchy & consistency audit | UX-001–008 | #54 |
| UX-010 | **HIGH** | Planned | Visual and accessibility regression gates | UX-003/004/009 | #55 |

## UX-001 — delivered foundation

UX-001 establishes the shared shell contract that later design tasks build on.

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

## UX-002 — active implementation

UX-002 removes navigation drift by treating destinations as product information architecture rather than duplicated UI strings.

### Navigation catalog
- Establish one server-side catalog for primary navigation, command search, current-location titles, localized descriptions, search aliases, and role visibility.
- Keep administrator-only destinations discoverable only to authorized administrators.
- Ensure Automation Center, Notification Inbox, account destinations, and administrative settings are covered without hidden URLs.

### Search and recent access
- Search by page name, capability description, keywords, or route.
- Group command results by workspace with localized context.
- Feed the same authorized navigation catalog into Recent/Favorites through JS interop instead of maintaining a stale client-only route list.
- Keep `Ctrl+Shift+P` recent/favorite access and surface it directly from the topbar and command palette.

### Workflow shortcuts
- Align Quick Actions with the most important site, content, AI, automation, execution, SEO, notification, and reporting workflows.
- Preserve tenant ownership and authorization boundaries; navigation changes do not change data access.

### Regression coverage
- Enforce unique routes and group keys.
- Verify localized catalog metadata.
- Verify important production destinations, most-specific route matching, admin visibility, and capability-keyword search.

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

## Next task after UX-002

**UX-003 — Responsive mobile/tablet application shell** remains the next Critical design task after UX-002 is validated and merged.
