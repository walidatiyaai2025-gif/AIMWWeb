# AI WordPress Manager — Program Master Plan

**Repository:** `walidatiyaai2025-gif/AIMWWeb`  
**Development branch:** `main`  
**Current baseline:** `155.92.0`  
**Plan owner:** Project maintainers  
**Status source of truth:** `src/AIWordPressManager.Web/wwwroot/development-status.json`

> This document is the mandatory starting point for every developer or AI coding agent working on the project. Read it before changing code, select work from the current phase, update the feature registry, build, test, and record the result.

---

## 1. Product vision

AI WordPress Manager is a multi-user, multi-site web application for securely connecting WordPress sites, synchronizing content into a local SQLite cache, managing content and operations, improving SEO with AI-assisted recommendations, and executing approved changes through auditable workflows.

The product must support:

- Multiple application users.
- Strict site ownership and tenant isolation.
- Multiple WordPress sites per application user.
- Live WordPress REST API operations.
- Local/offline SQLite cache.
- Arabic RTL and English LTR.
- Background execution, scheduling, retries, logs, notifications, and approvals.
- Secure credential storage and least-privilege access.
- Clear operational and development status reporting.

---

## 2. Architecture baseline

```text
AIWordPressManager
├── Domain            Entities, value objects, domain rules
├── Application       Use cases, interfaces, DTOs
├── Infrastructure    External integrations, AI providers, security
├── Persistence       EF Core, SQLite, configurations, initialization
└── Web               Blazor Server UI, API endpoints, orchestration
```

### Core ownership model

```text
Application User
└── Owned WordPress Sites
    ├── Credentials
    ├── Posts and Pages cache
    ├── Media cache
    ├── Taxonomy cache
    ├── Comments and WordPress users
    ├── SEO analyses
    ├── Operations and jobs
    ├── Schedules
    ├── Approvals
    └── Audit records
```

Every request carrying a `SiteId` must verify that the site belongs to the authenticated application user before reading local data, sending a WordPress request, or changing state.

---

## 3. Mandatory developer workflow

1. Pull and reset to the approved branch.
2. Read this plan and `development-status.json`.
3. Select one feature marked `in_progress` or the first unblocked `planned` feature in the active phase.
4. Confirm dependencies and acceptance criteria before coding.
5. Implement the smallest complete vertical slice.
6. Add or update validation, authorization, error handling, localization, and logging.
7. Run restore and build. Run relevant tests when available.
8. Never claim build or runtime success without actual evidence.
9. Update the feature status registry:
   - `completed`: implemented and acceptance criteria met.
   - `in_progress`: actively being implemented or partially functional.
   - `planned`: approved but not started.
   - `blocked`: cannot proceed until a named dependency is resolved.
10. Add release notes and increment the application version.
11. Commit with a focused message.

### Definition of Done

A feature is `completed` only when all applicable checks pass:

- Ownership and authorization enforced.
- Input validation implemented.
- Arabic and English UI text provided.
- Loading, empty, success, and error states handled.
- No mock data presented as real data.
- Database upgrade path considered.
- Logging and user-facing diagnostics included.
- Build succeeds in the target configuration.
- Runtime path tested manually or automatically.
- Feature registry and release notes updated.

---

## 4. Delivery phases

### Phase 0 — Governance and visibility

**Goal:** Make development measurable and repeatable.

Deliverables:

- Master development plan.
- Machine-readable feature registry.
- Interactive HTML development dashboard.
- Release/version discipline.
- Developer Definition of Done.
- Build and runtime verification policy.

Exit gate: Any developer can identify the current phase, next unblocked item, dependencies, and project completion percentage.

### Phase 1 — Platform foundation

**Goal:** Stable Blazor Server and clean solution architecture.

Scope:

- Layered solution structure.
- Dependency injection.
- EF Core and SQLite initialization.
- Localization and theming.
- shared UI components.
- health endpoints and build information.

Exit gate: Application starts reliably, database initializes automatically, and core shell is functional.

### Phase 2 — Identity and tenant isolation

**Goal:** Each application user sees and controls only their own data.

Scope:

- Registration and login.
- Password hashing and password change.
- Account profile.
- Site ownership.
- Ownership validation across local cache, APIs, operations, and schedules.
- Roles, permissions, sessions, lockout, audit, and MFA foundation.

Exit gate: Cross-user access is impossible through UI, URL manipulation, API calls, and background jobs.

### Phase 3 — WordPress site lifecycle

**Goal:** A user can connect, verify, manage, and remove WordPress sites safely.

Scope:

- First-run onboarding.
- Application Password setup.
- encrypted credential storage.
- Connection testing and diagnostics.
- Site settings and status.
- Initial synchronization.

Exit gate: New user can register, connect a site, synchronize it, and open a populated workspace.

### Phase 4 — Content and offline data

**Goal:** Reliable content visibility and editing with local cache support.

Scope:

- Posts, pages, media, categories, tags.
- Comments and WordPress users.
- Global and site-specific explorers.
- Workspace filtering.
- Offline snapshots and cache health.
- Bulk operations and conflict strategy.

Exit gate: All content modules are real, ownership-safe, paginated, searchable, and backed by live or clearly labeled cached data.

### Phase 5 — Operations and automation

**Goal:** Auditable execution of synchronous and background work.

Scope:

- Synchronization workspace.
- Execution Center.
- Job progress and activity logs.
- Schedules and retries.
- Multi-user background identity context.
- Notifications and failure recovery.

Exit gate: Scheduled and manual jobs run under the correct owner identity, survive restarts, and expose complete status.

### Phase 6 — SEO, AI, and approval workflow

**Goal:** Generate safe, reviewable, and executable improvements.

Scope:

- SEO audit.
- AI provider configuration.
- Prompt registry.
- AI suggestions with before/after values.
- Approval queue.
- Execution and rollback.
- Usage and cost logging.

Exit gate: No AI-generated change reaches WordPress without the configured approval policy and a traceable audit record.

### Phase 7 — Administration and security

**Goal:** Enterprise-grade administration.

Scope:

- User management.
- Roles and permissions engine.
- Feature flags.
- Session management.
- lockout and password reset.
- security events and audit trails.
- secrets rotation.

Exit gate: Administrators can govern access without direct database changes.

### Phase 8 — Reliability, backup, and observability

**Goal:** Recoverable and diagnosable production operation.

Scope:

- Backup and restore.
- export and import.
- structured logs and correlation IDs.
- health dashboard.
- retention policies.
- database integrity checks.
- performance and memory monitoring.

Exit gate: Operators can diagnose failures and restore service and data using documented procedures.

### Phase 9 — Quality, delivery, and production readiness

**Goal:** Repeatable release pipeline and verified product quality.

Scope:

- Unit, integration, authorization, and UI tests.
- CI build and test workflow.
- migrations and upgrade tests.
- security review.
- accessibility and responsive testing.
- deployment documentation.
- release packaging.

Exit gate: A tagged release can be built, tested, deployed, upgraded, and rolled back using documented automation.

---

## 5. Current priority order

1. Complete multi-user identity propagation for hosted background automation.
2. Verify `155.92.0+` with clean restore/build and runtime smoke tests.
3. Add authorization-safe schedule history and execution ownership identifiers.
4. Implement application user administration and roles.
5. Implement granular permissions.
6. Complete SEO audit and AI suggestion workflow.
7. Complete approvals and execution rollback.
8. Add backup/restore and production reliability work.
9. Build automated test and CI coverage.

---

## 6. Status rules

The canonical registry is JSON. Each feature contains:

- `id`: stable identifier; never reuse it.
- `phase`: numeric execution phase.
- `area`: functional module.
- `nameAr` and `nameEn`: display names.
- `status`: `completed`, `in_progress`, `planned`, or `blocked`.
- `priority`: `critical`, `high`, `medium`, or `low`.
- `version`: first version containing the completed or partial implementation.
- `notes`: factual current state, including known limitations.

Never change a status to `completed` solely because code was committed. Build/runtime evidence and acceptance criteria are required.

---

## 7. Versioning and release notes

- Functional feature: increment minor component, e.g. `155.92.0 → 155.93.0`.
- Runtime/build fix: increment patch component, e.g. `155.93.0 → 155.93.1`.
- Every version must include `src/AIWordPressManager.Web/Releases/<version>.md`.
- The footer/build information must show both version and branch.

---

## 8. Developer handoff template

```markdown
### Work item
Feature ID:
Phase:
Branch:
Version target:

### Implemented
-

### Files changed
-

### Security and ownership checks
-

### Validation and localization
-

### Verification
- Restore:
- Build:
- Tests:
- Runtime path:

### Known limitations
-

### Registry update
- Previous status:
- New status:
```

---

## 9. Dashboard locations

- In-app/offline HTML dashboard: `/development-status.html`
- Registry: `/development-status.json`
- Repository source:
  - `docs/PROGRAM_MASTER_PLAN.md`
  - `src/AIWordPressManager.Web/wwwroot/development-status.html`
  - `src/AIWordPressManager.Web/wwwroot/development-status.json`

The dashboard calculates totals directly from the registry and supports phase, status, priority, and text filters.
