# Laravel AIWMWeb Sync / Reconciliation Runtime

Authority: Issue #257. Worker 7. This slice is stacked on PR #263 and deliberately reuses its `ContentItem`, `MediaItem`, `Comment`, `TaxonomyTerm`, `ContentConflict`, `ContentSyncState` and `ContentRemoteDriver` contracts.

## Runtime

- Parent `sync_runs` own real lifecycle and counters: queued/running/partial/failed/completed/cancelled-ready.
- `sync_batches` cap WordPress pulls at 100 objects per request and persist page/cursor state.
- `sync_items` preserve per-object outcome and failed-item retry state.
- `sync_resource_versions` record the last reconciled local and remote hashes, version and last-seen run.
- Full sync deletion is conservative: a missing remote object is only confirmed after two completed full observations. A verified connector delete webhook can confirm immediately. Local records are retained and marked stale/tombstoned rather than destructively erased.
- `sync_site_leases` provide tenant/site concurrency exclusion and are released on terminal job failure.
- `sync_events` persist `SyncStarted`, `SyncProgressed`, `SyncConflictDetected`, `SyncFailed`, and `SyncCompleted`; the same names are dispatched through Laravel Events for Notifications/Operations consumers.

## Reconciliation rules

1. Remote changed only -> update Laravel projection and baseline.
2. Local changed only -> preserve local state; do not overwrite it from pull reconciliation.
3. Local + remote changed -> persist an open `ContentConflict`; no mutation occurs.
4. Confirmed remote deletion + unchanged local -> tombstone the remote version; preserve local row for evidence.
5. Confirmed remote deletion + changed local -> conflict, not deletion.
6. Temporary absence or one incomplete full scan never becomes a confirmed delete.

Conflict strategies are explicit: `KEEP_REMOTE`, `KEEP_LOCAL`, `MANUAL`, `RETRY_RECONCILIATION`. `KEEP_LOCAL` and `MANUAL` perform the requested WordPress write and then authoritative reread before the conflict is resolved.

## Webhooks

The public webhook endpoint is `/api/v1/sync/webhooks/connector`. The default verifier dynamically consumes the canonical Connector model and `ConnectorProtocol` from PR #260/#269, requires `content.read`, verifies protocol identity/signature/timestamp/nonce, bounds payloads to 256 KiB, requires event ID and resource identity, and persists event-level idempotency before queueing sync. Webhooks accelerate reconciliation; scheduled fallback remains authoritative coverage.

## Scheduled fallback

`sync:reconcile-stale` queues bounded incremental reconciliation for stale sites. Scheduler cadence is every 15 minutes with overlap prevention and a default 200-site ceiling per pass.

## WordPress E2E dependency

The feature tests prove deterministic orchestration/reconciliation with the real `ContentRemoteDriver` contract. Final disposable-WordPress evidence must be rerun after PR #265's WordPress runtime and PR #269/#260 Connector/Site runtime are integrated by the convergence Lead. Worker 7 does not copy those branches or create a competing connector protocol.

Required convergence E2E:

`WP post -> full sync -> local state -> remote edit -> incremental update -> local+remote edit -> conflict/no overwrite -> explicit resolution -> authoritative reread -> remote delete -> conservative tombstone`.

## Parity

Canonical `main` currently reports 931 operations and 213 Sync-domain rows. This stacked branch does not rewrite the canonical generated ledger from PR #261. Sync runtime evidence is provided by `SyncReconciliationRuntimeTest`; operation rows must only be terminalized by stable operation ID after this stacked PR is rebased onto the canonical ledger source. No bulk `PORTED` claim is made here.
