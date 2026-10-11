# Skill: Tenant Security

## Trigger
Any tenant-owned resource, authorization/RBAC change, direct identifier, secret, queue/job, cache/lock, approval, execution, evidence, billing, or external mutation.

## Checklist
1. Active tenant comes from authenticated TenantContext, never caller-owned fields.
2. Resolve resource through tenant-scoped query before mutation/read.
3. Require both permission and ownership where applicable.
4. Reject caller overrides of tenant, actor, provider-success, entitlement, ownership, or protected state.
5. Scope unique keys, idempotency, cache, locks, queue payloads, audit, and evidence by tenant.
6. Secrets remain encrypted/server-side and absent from browser/log diagnostics.
7. Foreign-tenant direct-ID tests fail closed with the intended status.
8. Queue/scheduled execution re-establishes tenant context and authorization invariants.
9. Super-admin/support behavior, if present, is explicit and audited.

## Required proof
Focused positive case + foreign-tenant/IDOR case + caller-owned-field case where relevant + full security gate.
