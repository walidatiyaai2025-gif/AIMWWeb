# Cross-domain security acceptance

This directory is the reusable adversarial tenant-isolation acceptance overlay for Issue #257. It does not own product features and does not merge worker implementations.

`targets.json` pins the live domain heads captured for this worker. CI verifies every executable branch still points at the pinned SHA before testing it. A moved branch must be reviewed and the manifest deliberately refreshed; stale security evidence is rejected.

The overlay test is copied into each candidate Laravel backend at CI time. It conditionally exercises only domains present on that exact head and is paired with each domain's focused native security tests. This keeps the security worker independent from integration order while allowing the Fresh Codex Final Convergence Lead to copy/run the same test after composition.

Coverage includes shared tenant scopes, direct-ID isolation, cache/lock/idempotency namespaces, queue context cleanup, Connector signature/timestamp/identity/replay/revocation/rotation/scope attacks, AI provider-secret isolation, admin retry/cancel/export ownership, and billing subscription/quota isolation.

Advanced sync reconciliation and notifications are intentionally not reported as passing while their remote branches lack reviewable final implementations. Basic content sync remains covered through the content candidate.
