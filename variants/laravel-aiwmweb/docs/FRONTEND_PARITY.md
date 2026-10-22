# Laravel AIWMWeb Frontend Parity Evidence

Issue: #257  
Variant: `LARAVEL_AIWMWEB`  
Worker: `worker/laravel-aiwmweb-full-frontend`

## Authority inspected

The current AIMWWeb web product is the visual/workflow authority. This worker inspected the live `main` navigation catalog, `MainLayout`, route guard/error behavior, shared UI patterns, responsive shell CSS, theme tokens, RTL/LTR behavior, and the current screen inventory. The ASP.NET product was read only and is not modified by this branch.

Parity anchors carried into the Laravel React application:

- grouped 284px application sidebar with 88px collapsed desktop state;
- sticky translucent top bar, breadcrumb, page title/context, quick-search command palette;
- current dark-first surface system and gold `#c5a45d` accent, with light appearance support;
- cards/panels, toolbar, tables, dialogs, loading/empty/error states and explicit access state;
- 44px interaction targets, focus-visible treatment, skip link, reduced-motion support;
- mobile off-canvas navigation at the current 700px breakpoint;
- English/Arabic direction switching with logical CSS properties instead of one-off RTL overrides.

## Route / screen map

The React catalog contains **48 governed workspace routes**. It preserves current AIMWWeb route names where they exist and adds explicit Laravel parity destinations for current required domains that do not yet have a dedicated route in the source product.

| Group | Coverage |
| --- | --- |
| Overview | Dashboard, Welcome, Sites, Connect Site, Site Details, Explorer, System Overview |
| Content | Content Hub, Posts, Pages, Media, Comments, Categories & Tags, Categories, Tags, WordPress Users |
| SEO & Approvals | SEO Audit, Findings, Recommendations, Approval Queue, Evidence & Receipts |
| AI Workspace | AI Center, Content Planner, AI Providers/configuration, Prompt Templates, AI Usage & Cost |
| Automation & Operations | Automation Center, Operations Hub, Site Operations, Site Reliability, Execution Center, Sync, Schedules, Notifications, Email History |
| Reports & Insights | Reports & Exports, Import / Export |
| System & Account | System Health, Logs, Diagnostics, Backup/Restore, Settings, Workspace Hubs, Profile, Billing, Application Users, Roles/Permissions, Sessions |

The route catalog is the single source for sidebar navigation, breadcrumbs, command search, capability state and route tests. No `href="#"` navigation exists.

## API integration contract

`GET /tenants/{tenant}/context` is the only backend contract this worker extends because Tenant Core already owns tenancy and authorization. It returns only authenticated, tenant-scoped facts:

- current user;
- active tenant;
- the authenticated user's active tenant memberships for switching;
- resolved current-tenant permissions;
- connector contracts;
- capability contracts;
- read API discovery map;
- mutation/action discovery map.

The last four maps are empty on this head because no broad Laravel domain APIs were integrated when the worker branch was created. Empty discovery is intentional. A screen whose API/capability is absent renders **Pending backend integration**, **Disabled by site owner**, **Connector capability unavailable**, **Protocol upgrade required**, **Site disconnected**, or **Permission required** as applicable. It does not render sample records, fake statistics, provider readiness, fake SEO findings, fake job counts, or local-only successful mutations.

Parallel backend workers can integrate without changing frontend architecture by advertising endpoints/capabilities/actions from tenant context. The typed client then uses those exact server-advertised endpoints.

## Governed controls

The catalog declares **40 domain action control slots** across connect, content create/bulk/publish, media upload, moderation, taxonomy, SEO audit/approval, AI generation, automation, execution retry/cancel, synchronization, schedules, reporting, import/export, backup/restore, user administration, roles and sessions.

An action button is enabled only when:

1. tenant permission is satisfied;
2. required connector scope is advertised by a connected connector;
3. explicit capability state does not block it; and
4. the backend advertises a typed action contract.

If any condition fails, the button is disabled with the concrete reason. Mutation success notifications are emitted only after a successful HTTP response.

## Foundation evidence

- React + TypeScript mounted through Laravel Vite rather than a second application runtime.
- React Router tenant-scoped routing with deep-link Laravel fallback.
- TanStack Query request/cache/error/loading state.
- typed `ApiError` handling for 401/403/404/409/422/5xx and validation payloads.
- dynamic server-described forms with required/email validation and server field errors.
- reusable shell, state panels, dialog, action controls, data table, pagination, toasts, loading skeletons and empty states.
- class error boundary plus explicit tenant-context bootstrap failure state.
- tenant switching preserves the current workspace path.
- dashboard renders only backend-returned `metrics`; an empty result stays visibly empty.

## RTL / localization evidence

- English and Arabic labels/descriptions are embedded in the route authority catalog.
- `html.lang` and `html.dir` switch at runtime and persist as a user preference.
- shell, nav active edge, borders, spacing and overlays use inline/block logical properties.
- tables use start alignment and horizontal scroll regions.
- dialog layout, forms, validation, pagination and toolbar work in both directions.
- direction switch is covered by a frontend test.

## Responsive / accessibility evidence

- 1180px, 920px, 700px and 430px adaptations.
- desktop collapse and mobile off-canvas navigation are separate behaviors.
- skip-to-content link and focus-visible treatment.
- semantic nav/main/table/form/status/alert/dialog structures.
- modal focus handoff, Escape close, prior-focus restoration.
- keyboard `Ctrl/Cmd+K` command palette.
- visible disabled reason text and `aria-disabled` for unavailable domain controls.
- reduced-motion and increased-contrast media preferences.

## Tests

Frontend acceptance covers:

- broad route inventory and duplicate/dead hash route regression;
- tenant permission disabled state;
- site-owner connector-disabled state;
- pending API integration state;
- fully enabled permission + connector + API state;
- disabled action with explicit reason;
- tenant switching path preservation;
- RTL/LTR runtime switching;
- 422 validation and 403 failure semantics with no fake success.

Laravel feature coverage verifies:

- frontend context is authenticated and tenant-scoped;
- only the user's active tenants are advertised;
- permissions are resolved from current Tenant Core RBAC;
- no fake connector/API/action capabilities are advertised;
- cross-tenant context access remains 404;
- deep SPA paths are guarded by real `tenant.view` authorization.

## Integration status

Frontend architecture and visual shell are implemented. Broad domain data/mutations remain intentionally capability-aware pending states until Codex/parallel workers integrate their real Laravel APIs. This is a surfaced integration dependency, not simulated completeness.
