# Skill Router

Select the minimum relevant skill set after reconstructing live state.

| Task shape | Primary skill | Add when needed |
|---|---|---|
| New/ported canonical capability | `feature-closure.md` | tenant-security, wordpress, seo-ai, billing |
| Production/user-visible defect | `bug-root-cause.md` | domain skill + tenant-security |
| Auth/RBAC/tenant/IDOR/secret concern | `tenant-security.md` | bug-root-cause or feature-closure |
| WordPress content/site/connector mutation | `wordpress-integration.md` | tenant-security + feature/bug |
| SEO audit/suggestion/AI provider workflow | `seo-ai-operation.md` | tenant-security + feature/bug |
| Plan/subscription/payment/entitlement | `billing-operation.md` | tenant-security + feature/bug |
| Red GitHub Actions / tests / build | `ci-repair.md` | domain skill that owns the failing behavior |
| Merge/recovery/worker replacement | `handoff-and-merge.md` | ci-repair if red |

## Routing rules

1. Never choose a skill from the task title alone; inspect the real code path and failure/evidence.
2. Use at most one primary execution skill plus the necessary domain/security skill(s).
3. Tenant-owned mutation automatically invokes tenant-security checks even if the primary task is elsewhere.
4. External mutation automatically invokes the relevant WordPress/billing/AI boundary checks.
5. Red CI on an active task is repaired on the same branch/PR; do not route it as a new feature task.
6. Every completed task finishes with the handoff/merge checklist from the repository operating system.
