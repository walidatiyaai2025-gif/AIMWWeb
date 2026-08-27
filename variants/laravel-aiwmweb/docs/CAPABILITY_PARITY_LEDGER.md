# Capability Parity Ledger

Authority: AIMWWeb Issue #257

This ledger records only the accepted vertical slice. Broad feature census and remaining feature porting stay deferred.

Allowed terminal states: `PORTED`, `ADAPTED`, `VERIFIED_UNAVAILABLE_EXTERNAL`, `BLOCKED`.

| Capability ID | Operation ID | Current AIMWWeb source | User-visible behavior | Tenant-owned data | Laravel destination | State | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DEMO-SITES | site-crud | Site management | Add, edit, remove, list and inspect tenant sites | Yes | Site API and console | `PORTED` | Tenant-scoped feature and IDOR tests |
| DEMO-CONNECTOR | connector-pair-verify | WordPress connection | Pair once, negotiate capabilities, restrict scopes and verify health | Yes | Connector protocol and plugin | `ADAPTED` | HMAC/timestamp/nonce/scope/revocation tests |
| DEMO-SYNC | content-incremental-sync | WordPress content inventory | Incrementally synchronize posts/pages and SEO metadata | Yes | SyncSiteJob | `PORTED` | Executed-job vertical-slice test |
| DEMO-SEO | seo-audit | SEO checks | Produce explainable findings without invented scores | Yes | RunSeoAuditJob | `ADAPTED` | Deterministic finding assertions |
| DEMO-AI | ai-suggestion | AI-assisted remediation | Generate constrained proposed changes; never publish directly | Yes | GenerateSuggestionJob | `ADAPTED` | Provider boundary and approval test |
| DEMO-APPROVAL | approve-execute-verify | Controlled remediation | Approve, execute once, reread, verify and retain immutable evidence | Yes | Approval, execution and evidence services | `PORTED` | Full approved-change journey test |
| _inventory pending_ | _inventory pending_ | _pending census_ | _pending census_ | _pending classification_ | _not yet ported_ | `BLOCKED` | Remaining parity denominator not yet inventoried |

No capability may be removed from the denominator or marked terminal without source and acceptance evidence.
