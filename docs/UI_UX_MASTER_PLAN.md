# AI WordPress Manager — UI/UX Master Plan

**Workstream:** Product Design / UI / UX  
**Priority:** Front-line delivery priority  
**Source branch for UX-001:** `agent/ux-001-premium-shell`  
**Tracking:** GitHub Issues #46–#55

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

| ID | Priority | Task | Dependency | GitHub |
|---|---|---|---|---|
| UX-001 | **CRITICAL** | Premium design system & application shell foundation | — | #46 |
| UX-002 | **CRITICAL** | Navigation information architecture & discoverability | UX-001 | #47 |
| UX-003 | **CRITICAL** | Responsive mobile/tablet application shell | UX-001 | #48 |
| UX-004 | **CRITICAL** | WCAG AA accessibility, keyboard & focus UX | UX-001 | #49 |
| UX-005 | **HIGH** | Forms, validation, confirmations & destructive-action UX | UX-001/004 | #50 |
| UX-006 | **HIGH** | Data tables, filters, bulk actions & dense workspace UX | UX-001/003 | #51 |
| UX-007 | **HIGH** | Loading, empty, success, warning, offline & error states | UX-001 | #52 |
| UX-008 | **HIGH** | Arabic RTL / English LTR visual parity audit | UX-001/003 | #53 |
| UX-009 | **HIGH** | Page-by-page visual hierarchy & consistency audit | UX-001–008 | #54 |
| UX-010 | **HIGH** | Visual and accessibility regression gates | UX-003/004/009 | #55 |

## UX-001 — implementation scope

UX-001 establishes the shared shell contract that later design tasks build on.

### Design system foundation
- Extend the existing `--ui-*` token layer with shell semantics instead of creating a competing token system.
- Standardize shell width, content gutters, elevations, radii, focus treatment, touch targets, and motion.
- Preserve existing runtime accent selection and light/dark mode.

### Application shell
- Refine sidebar, brand block, grouped navigation, active states, topbar hierarchy, and content canvas.
- Remove stale hard-coded branch/version decoration from CSS; build identity is rendered only from `BuildInformationService`.
- Make primary landmarks and interactive controls screen-reader identifiable.
- Add a keyboard skip link to the application content.

### Discoverability baseline
- Surface AI Center, AI Usage & Cost, Content Planner, Prompt Templates, and provider configuration in the AI workspace.
- Keep important destinations searchable in the command palette.
- Ensure page grouping/title resolution understands the production AI routes.

### Responsive baseline
- Keep desktop navigation stable, tablet topbar compact, and mobile navigation off-canvas.
- Resolve conflicting legacy mobile navigation rules so the off-canvas sidebar remains vertically usable.
- Keep controls usable with touch and prevent application-level horizontal overflow.

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
