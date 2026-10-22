# Laravel AIWMWeb Frontend API Coordination

Issue: #257  
Frontend branch: `worker/laravel-aiwmweb-full-frontend`

This document records the **live parallel API contracts fetched after this frontend worker started**. They are typed in `backend/resources/js/contracts.ts`. They are not copied, reimplemented, or reported as integrated until the integration authority actually merges/composes the owning PRs.

## PR #260 — WordPress connector / governed demo runtime

Observed tenant endpoints:

- `GET|POST /api/tenants/{tenant}/sites`
- `GET|PATCH|DELETE /api/tenants/{tenant}/sites/{site}`
- `POST /api/tenants/{tenant}/sites/{site}/pairing`
- `GET /api/tenants/{tenant}/sites/{site}/connector`
- `PUT /api/tenants/{tenant}/sites/{site}/connector/scopes`
- `POST /api/tenants/{tenant}/sites/{site}/connector/rotate`
- `DELETE /api/tenants/{tenant}/sites/{site}/connector`
- `POST /api/tenants/{tenant}/sites/{site}/verify`
- `POST /api/tenants/{tenant}/sites/{site}/sync`
- `GET /api/tenants/{tenant}/sync-runs/{run}`
- `GET /api/tenants/{tenant}/sites/{site}/content`
- `POST /api/tenants/{tenant}/sites/{site}/audits`
- `GET /api/tenants/{tenant}/audits/{audit}/findings`
- `PUT /api/tenants/{tenant}/ai/provider`
- suggestion / approval / execution / receipt mutation and evidence endpoints.

Current backend permission names observed in this PR include `tenant.view`, `sites.manage`, `connector.manage`, `seo.manage`, `ai.manage`, `ai.use`, `approvals.manage`, and `executions.manage`. Connector protocol version `1` advertises enabled scopes and explicitly enforces them. The connector runtime uses scopes including `health`, `content.read`, `content.execute`-derived scopes, and `connector.manage`.

## PR #263 — Content publishing platform

Observed site-scoped API root:

`/api/v1/tenants/{tenant}/sites/{site}`

Coverage:

- posts/pages list, create, show, edit, state transitions, delete and bulk actions;
- revisions list / compare / restore;
- media list / queued upload / edit / guarded delete;
- comments list / moderation / reply / bulk moderation;
- taxonomy list / discover / create / edit / delete / assignments / bulk assignments;
- sync start / sync status;
- conflicts list / explicit resolution;
- queued import / export and transfer status.

Observed permissions are `content.view` for reads/export and `content.edit` for mutations/import/sync. Mutations preserve the worker's HTTP `409` conflict semantics; the React API client surfaces those conflicts and does not convert them to local success.

## PR #264 — Administration / operations control plane

Observed tenant admin root:

`/tenants/{tenant}/admin`

Coverage:

- members and member lifecycle;
- roles and permissions;
- session enumeration/revocation;
- typed settings;
- schedules;
- automation definitions, triggering and approval;
- operations list/detail/cancel/retry;
- sync operations;
- backup / restore approval orchestration;
- redacted logs and diagnostics;
- reports, queued exports and download.

Observed permissions include `members.manage`, `roles.manage`, `sessions.manage`, `settings.manage`, `operations.manage`, `backup.manage`, `reports.manage`, and `tenant.view` for the read surfaces explicitly authorized that way.

## Frontend integration rule

The React application deliberately does **not** enable an endpoint just because its path is known. `FrontendContext.api`, `FrontendContext.actions`, `FrontendContext.connectors`, and `FrontendContext.capabilities` remain the runtime authority. Until Codex/integration composition advertises an integrated endpoint/capability for the active tenant/site, the control remains disabled or the screen shows a pending/connector/permission state.

This protects four invariants:

1. no API from an unmerged worker branch is presented as live;
2. no tenant/site ID is guessed by the browser;
3. no connector scope is inferred from feature names;
4. no mutation produces a success notification before a successful server response.

## Integration handoff

When composing PRs #260/#263/#264 with the frontend, the integration lead should populate the existing context discovery maps from the **actual active tenant/site and server routes**, using the typed path builders in `contracts.ts` only as compile-time coordination evidence. If an API remains absent, leave its discovery key absent: the UI already handles that state explicitly.
