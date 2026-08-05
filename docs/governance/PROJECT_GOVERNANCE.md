# AI WordPress Manager — Project Governance

## Product scope

The MVP covers secure WordPress site registration, connection testing, local SQLite synchronization, content exploration, SEO audit, approval/execution workflow, diagnostics, bilingual UI, and operational logging. V1.0 completes the existing screens and services before adding new screens.

## Roles

- Product Owner: approves scope and release acceptance.
- Project Manager: backlog, risks, dependencies, and release coordination.
- Technical Lead: architecture, code review, build quality, and versioning.
- Backend/Integration Developer: WordPress, persistence, automation, and APIs.
- Web Developer: Blazor UX, localization, accessibility, and user workflow.
- QA: functional, integration, regression, and release verification.

## Definition of Done

A task is complete only when the implementation exists, the solution builds, failure paths are handled, user-visible feedback is present, relevant data is persisted, and documentation/task evidence is updated.

## Git and release policy

- `main` is the release baseline.
- `feature/system-health` is the active stabilization branch.
- Every change uses a descriptive commit and version update for user-facing releases.
- CI restore/build checks are required before merge.

## Environments

- Development: local .NET 8 + SQLite.
- Staging: production-like configuration with non-production WordPress sites.
- Production: protected secrets, HTTPS, backups, health checks, and controlled deployment.

## Backlog and release stages

1. Functional completion of current screens.
2. Integration and failure-path verification.
3. Localization and accessibility completion.
4. Performance/security hardening.
5. Release candidate and production readiness.

## Risk register

| Risk | Owner | Mitigation |
|---|---|---|
| WordPress API incompatibility | Integration Lead | Capability discovery, classified errors, retries |
| Credential exposure | Technical Lead | Protected secrets, no logging of passwords |
| Partial synchronization | Backend Lead | Per-stage execution logging and resumable jobs |
| Build regression | Technical Lead | CI build gate and clean rebuild scripts |
| Translation drift | Web Lead | Central language service and English-first keys |
| SQLite corruption | Backend Lead | Health checks, backups, and transaction boundaries |
