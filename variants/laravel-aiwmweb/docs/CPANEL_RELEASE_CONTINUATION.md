# cPanel release continuation — Issue #1384

Authority: #257. Repository: `walidatiyaai2025-gif/AIMWWeb`.
Scope: `variants/laravel-aiwmweb/**` only. Integration: `worker/laravel-aiwmweb-closure-composition`.
Canonical task branch: `worker/1384-cpanel-durable-ai-release`.

This is a continuation checkpoint, not a substitute for live GitHub evidence. On every resume, read PCC main, AGENTS.md, Issue #1384, current composition, the canonical branch and PRs, exact-head CI, and current artifact manifests. Continue the same task; do not duplicate another worker's active ownership.

## Accepted foundation and follow-up

- PR #1385 merged at `0d35b4b44a09aaef0f4b1701b8f2336f5e2cf1e8`: durable encrypted AI batches, one global provider permit, fencing, incremental results, quota-safe pause/resume, ambiguous-request reconciliation, bounded cron/queue admission, cPanel installer and AR/EN instructions.
- Follow-up PR #1388 continues the same canonical task: database-only runtime readiness, cache/session/lock probes, accurate disabled AI/cron states, forced safe database drivers, HTTPS/HSTS and secure cookies, preserved APP_KEY validation, generation receipt linkage, and safe retrieval of completed results without creating another provider request if context changes.
- The cPanel artifact forces its runtime profile through a private marker. It must ship with AI, cron and hosting preflight disabled.
- Old kit source `6753e857` is not approved for live AI usage.

## Finish repository work before release

1. Finish the existing PR #1388 on its exact latest head. Repair CI on the same branch, never by bypassing gates.
2. Verify PHP/MySQL, SQLite, cross-domain security, frontend tests/typecheck/build, WordPress compatibility, fresh MySQL installer/recovery, database cache/session/locks, 50-action durability, action-18 rate limiting, crash ambiguity, independent competing workers and double cron.
3. Merge normally into composition only with all required triggered checks green. Re-fetch composition and verify its post-merge acceptance, convergence, parity reconciliation and closure audit.
4. Retrieve the Actions runtime artifact for that exact merged SHA. Extract its installable cPanel ZIP, verify all manifest hashes, production vendor/assets, private layout, source/version manifest, absence of .env/secrets, and SHA-256. Do not distribute an unmerged development ZIP as production-ready.
5. Deliver the actual ZIP, checksum, source SHA, CI links and release report. Preserve this checkpoint and add the final handoff to #1384. Do not close the issue while acceptance remains unverified.

## Hosting/support hold

The owner is waiting for hosting support regarding the remote Ollama server's storage I/O problem. This release task must not SSH into, restart, modify or benchmark that server. Infrastructure recovery and subsequent Ollama health validation belong to the infrastructure owner under separate authorization. Do not enable Ollama or spend provider credits for this work.

Laravel installation and core login must work with AI unavailable. Keep `CPANEL_AI_ENABLED=false`. No permanent queue worker, Supervisor, Horizon or `schedule:work` is allowed in the cPanel profile.

Before enabling cron, the actual host must verify PHP web/CLI versions and extensions, private application/document-root layout, HTTPS and Directory Privacy identity mapping, GNU timeout/flock/pcntl behavior, process/memory/time limits, database transactions/locks, and two concurrent cron launches. Verify backup restoration in a disposable database before upgrading any existing installation.

The bounded cron contract is one shared nonblocking flock, an outer 110-second timeout (+5-second kill grace), bounded scheduler/queue/AI child processes, provider HTTP timeout no greater than 30 seconds, and no more than one AI action per invocation. Expired or ambiguous chargeable work must never be replayed automatically.

## Release decision

- `READY FOR CPANEL DEPLOYMENT` requires actual hosting acceptance and all remaining release gates.
- `READY FOR CONTROLLED STAGING ONLY` is the honest outcome when repository/CI/installer evidence passes but cPanel-specific acceptance is outstanding.
- `BLOCKED` applies if any code, security, CI, artifact or required installation gate remains unresolved.

No production deployment is authorized by this plan. Preserve APP_KEY, credentials, uploads, runtime data and completed AI results. Never reset or reseed production data to make installation or upgrade succeed. Automatic database rollback is not implemented or claimed; use the documented verified backup/recovery procedure.
