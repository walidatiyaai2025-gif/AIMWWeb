# Laravel AIWMWeb — Content Platform Parity Ledger

Authority: Issue #257 and current ASP.NET AIMWWeb content surfaces. This ledger marks only behavior implemented in this worker branch; runtime WordPress connectivity still composes through the integration-owned Site / WordPressGateway contracts.

| Capability | ASP.NET evidence | Laravel implementation | Verification |
|---|---|---|---|
| Posts/pages browse, search, status filter, pagination | `ContentExplorer.razor` | `ContentApiController::index`, normalized `ContentItem` | `ContentPlatformIsolationTest` sync/query model coverage |
| Edit title/slug/body/excerpt/status/schedule/featured media/categories/tags/template/comments/pings/format/sticky | `ContentEditor.razor`, `IWordPressPostEditorService` | versioned content create/update/state APIs + dual path driver | conflict test proves no stale overwrite |
| Draft/pending/publish/future/private | `ContentEditor.razor` | state mutation and validation | service payload/state handling |
| Bulk pending/publish/draft/trash | `ContentExplorer.razor` | queued `BulkContentMutationJob` | site-scoped bulk rejection test |
| Revision capture/compare/restore | ASP.NET editor uses expected modified version before writes | `ContentRevision`, compare + restore endpoints | `ContentRevisionDiffTest` |
| Media library and featured-media awareness | Explorer/editor media picker | media list/update/delete, reference guard, queued 200MB uploads | tenant scope + upload queue design |
| Comments list/filter/paging/moderation/reply | `CommentsManager.razor` | approve/unapprove/spam/unspam/trash/restore/delete/reply + queued bulk | tenant-scoped `Comment` model |
| Categories/tags | Explorer | normalized taxonomy CRUD/assignment | tenant-scoped `TaxonomyTerm` model |
| Custom taxonomy discovery | WordPress capability surface | semantic `taxonomy.discover` + custom taxonomy mutation path | connector adapter boundary |
| Sync summaries / local cache | `WordPressExplorerSnapshot`, `IWordPressSynchronizationService` | initial/incremental/manual queued sync, retry, progress, failed state, remote-modified cursor | initial sync test |
| Optimistic concurrency | `ExpectedModifiedGmt`, `ForceOverwrite` in `IWordPressPostEditorService` | modified/version/hash comparison, persisted `ContentConflict`, HTTP 409 | conflict test proves remote mutation not invoked |
| Import/export | existing data export patterns and content operations | tenant/site-scoped queued JSON export/import with progress/failure state | `ContentTransfer` isolation coverage |
| REST + connector execution | `IWordPressApiClient`; Issue #257 connector direction | native WP REST when credentials are present, integration-owned semantic gateway fallback | no duplicate connector/site architecture |
| Tenant/site isolation | Issue #257 Tenant Core | inherited `BelongsToTenant` + explicit site predicates on direct IDs/bulk operations | `ContentPlatformIsolationTest` |

## Integration contract

This branch intentionally does not define `App\\Models\\Site` or `App\\Connector\\WordPressGateway`. PR #260 currently owns those integration contracts. `NativeWordPressRestPath` dynamically uses the integrated Site model when REST credentials are available; `ConnectorSemanticPath` dynamically composes the integration-owned gateway otherwise. This prevents a competing tenancy, pairing, or connector architecture.
