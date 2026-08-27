# Capability Parity Ledger

Authority: AIMWWeb Issue #257

Allowed terminal states: `PORTED`, `ADAPTED`, `VERIFIED_UNAVAILABLE_EXTERNAL`, `BLOCKED`.

| Capability ID | Operation ID | Current AIMWWeb source | User-visible behavior | Tenant-owned data | Laravel destination | State | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| ADMIN-MEMBERS | members.list/add/update/remove | Issue #257 administration family | Manage tenant members without cross-tenant disclosure | Yes | `AdminOperationsController` + `AdministrationService` | `PORTED` | Member IDOR + last-owner tests |
| ADMIN-RBAC | roles.list/save/assign | Issue #257 roles/permissions family | Custom roles and permission assignment with privilege safeguards | Yes | `AdministrationService::saveRole/updateMember` | `PORTED` | Protected-permission escalation test |
| ADMIN-SESSIONS | sessions.list/revoke/revoke-others | Issue #257 sessions family | Inspect own active sessions and revoke other sessions | User + tenant audit | `AdministrationService` session operations | `PORTED` | Session revocation isolation test |
| ADMIN-SETTINGS | settings.platform/tenant/site/user | Issue #257 settings family | Typed scoped settings; platform-safe read-only; encrypted secrets | Yes | `scoped_settings` + `AdministrationService` | `PORTED` | Secret encryption and tenant isolation test |
| OPS-SCHEDULER | schedule.list/save/dispatch | Issue #257 scheduling family | Recurring controlled task definitions with timezone/next/last/retry state | Yes | `scheduled_tasks` + `ops:dispatch-due` | `PORTED` | Two-tenant due-task dispatch test |
| OPS-AUTOMATION | automation.list/save/trigger/approve | Issue #257 automation family | Whitelisted triggers/actions with optional approval and history | Yes | `automation_rules` / `automation_runs` | `ADAPTED` | Arbitrary execution deliberately unsupported |
| OPS-CENTER | operations.list/detail/retry/cancel | Issue #257 job/execution operations family | Persisted queued/running/succeeded/failed/cancelled/retrying state and correlation IDs | Yes | `operation_executions` / `operation_logs` | `PORTED` | Tenant-scoped operation direct-ID test |
| OPS-SYNC | sync.operations | Issue #257 sync-control family | Central sync operational state/retry surface; no content-sync internals | Yes | `operation_executions` + `SyncOperationsGateway` | `ADAPTED` | Worker 2 internals intentionally not duplicated |
| OPS-BACKUP | backup.L1/L2/L3 | Issue #257 backup orchestration family | Risk-aware backup requests, approvals, manifests, operation tracking | Yes | `backups` + `ConnectorBackupGateway` | `BLOCKED` | Orchestration persists honestly; connector execution waits for Worker 1 gateway |
| OPS-RESTORE | restore.request/approve | Issue #257 restore orchestration family | High-risk approval gate and tracked connector handoff | Yes | `restore_requests` + `ConnectorBackupGateway` | `BLOCKED` | Cross-tenant restore test; Worker 1 execution gateway pending |
| OPS-LOGS | logs.search/diagnostics | Issue #257 logs/diagnostics family | Tenant-filtered execution/audit diagnostics with recursive secret redaction | Yes | `operation_logs` + immutable `audit_events` | `PORTED` | Redaction + tenant isolation test |
| OPS-REPORTS | reports.summary/export/download | Issue #257 reports/exports family | Real persisted-data reports and queued private CSV exports | Yes | `report_exports` + `GenerateReportExport` | `PORTED` | Queue and export direct-ID isolation test |

Connector-backed capabilities remain non-terminal until the WordPress runtime gateway is integrated and acceptance evidence proves execution/verification. No connector success is synthesized by the Laravel orchestration layer.
