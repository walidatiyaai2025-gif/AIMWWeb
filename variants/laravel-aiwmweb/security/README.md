# Cross-domain security acceptance

This directory is the reusable adversarial tenant-isolation acceptance overlay for Issue #257. It does not own product features and does not merge worker implementations.

`targets.json` identifies the composed convergence candidate. CI always tests the pull request's exact checked-out head; it does not reuse green status or source from isolated worker branches.

The overlay test is copied into the composed Laravel backend at CI time and exercises the domains present on that exact head.

Coverage includes shared tenant scopes, direct-ID isolation, cache/lock/idempotency namespaces, queue context cleanup, Connector signature/timestamp/identity/replay/revocation/rotation/scope attacks, AI provider-secret isolation, admin retry/cancel/export ownership, and billing subscription/quota isolation.

Advanced sync and real Email/Notifications are included from PRs #275 and #276 and must pass on the composed exact head before integration.
