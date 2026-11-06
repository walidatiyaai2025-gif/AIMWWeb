# AIMWWeb — bounded Ollama and truthful live operations (2026-11-06)

**Scope:** Laravel-only AIMWWeb; refs #1384, #1458, #1408 and the historical VPS I/O evidence in CPANEL_DEPLOYMENT_OLLAMA_SUPPORT_RESUME.md. This file does **not** assert that ai.walidatiya.com is operational today, that a production setting changed, or that a ZIP is deployed.

## Live production evidence (owner-exported Runtime Inspector v1.0.281)
- Installed release v1.0.281 at e556b358136db4a7a82005e62b5e673ac18edf90; PHP 8.3; MySQL reachable.
- The site is CONNECTED and Connector verified with `content.read`; canonical local counts: posts 410, pages 19, media 307; last canonical sync marked completed.
- UI legacy `pages.read`, `taxonomy.read` and other per-resource scopes are **not** native Connector scopes. The `content.read` contract authorizes **reads only**. Do not pretend it permits trash/publish/update, and never widen `content.update` implicitly.
- Production `queue.default=sync`. That is an **unsafe execution profile for long-running jobs**: HTTP requests may execute jobs inline. Do not enable large or parallel AI/sync batches until a bounded, durable worker is provisioned and observed.
- The exported browser observations show a burst of repeated `/api/tenants/:id/sites` GETs at growing latencies; investigate React Query duplication/retries separately, without inventing a server failure from HTTP 200 responses.

## Existing application contract and delivery

The Laravel app already includes `BatchService`, `durable_ai_gate`, encrypted per-item requests, one-global-inflight claims, pause/resume, budget admission, `cpanel:tick`, and nonoverlapping `runtime/cpanel/cron.sh`. Existing provider adapter `openai-compatible` can target an HTTPS OpenAI-compatible `/v1` endpoint, but **requires an authenticated and safe private ingress**. Ollama itself must not be bound publicly unauthenticated.

This implementation adds a **member-scoped read-only summary** at `GET /api/tenants/{tenant}/ai-batches`. It reports at most 20 batches and counts of completed, running, pending, paused, failed and needs-reconciliation items; no prompts, model outputs, request bodies or credentials. AI Center contains an opt-in polling report: 8 seconds with active batches, 30 seconds when idle, no polling in hidden tabs. The sync workspace has opt-in 10-second **read-only** status polling including discovered/created/updated/unchanged/failed counters. Existing `GET` endpoints do not initiate provider calls or remote WordPress sync.

## Safe activation gates — do not skip

1. **VPS storage recovery:** earlier verified DeluxHost Z-4 (2 vCPU/8 GB) suffered severe sustained I/O pressure with blocked jbd2/writeback. Owner reports the Ollama server is ready, but new health and support evidence must establish that the stall is resolved. Before that, no benchmark, model pull, mass AI load or forced reboot.
2. **Protect the ingress:** expose `https://ai.walidatiya.com/v1` only through TLS reverse proxy enforcing strong server-side bearer authentication and rate/body/time limits; keep port 11434 bound to loopback/private network and firewall it. Do not store the proxy token in GitHub, public JS, release ZIP or reports. Configure it using encrypted per-tenant provider credentials and validate secret redaction.
3. **Single model and concurrency:** configure one approved local model; `OLLAMA_NUM_PARALLEL=1`, `OLLAMA_MAX_LOADED_MODELS=1`, `OLLAMA_MAX_QUEUE=1` (verify supported Ollama service version); avoid automatic provider failover, model downloads or retry storms. Start with a single short, human-reviewed draft request **only after host recovery**. A weak 1.7b model must not publish Arabic SEO content without review.
4. **Hosting queue:** switch production from `QUEUE_CONNECTION=sync` to a **tested database queue** as part of a signed, backed-up cPanel update, preserving existing jobs and settings. Use one nonoverlapping bounded Cron tick only after the hosting provider has validated PHP CLI, `flock`, `timeout`, memory and process limits. No permanent daemon, Supervisor/Horizon or competing workers.
5. **Limits:** keep `CPANEL_AI_ENABLED=false`, `CPANEL_CRON_ENABLED=false`, `CPANEL_PREFLIGHT_PASSED=false` until independently verified. Enable with conservative caps at most **one global in-flight** item and minimum interval >=5 seconds, max queued jobs 20, pending AI actions <=500, daily call/token/cost budgets enforced by `config/cpanel.php`. Do not expose bulk admission as provider fan-out.
6. **Timeout contract:** the current cPanel provider limit is 30 s; legacy CPU-only Ollama 4b-instruct attempts exceeded 240 s. Do not merely raise PHP-FPM HTTP or worker limits. If realistic generation is slower, implement and verify a separate authenticated, idempotent **external asynchronous AI runner** with leases/fencing, signed callbacks or secure pull, immutable completion receipts and no auto-retry after ambiguous delivery.
7. **Acceptance:** real operator-scoped UI reports; real signed WordPress 6-resource sync, HTTP errors and failed rows; 50-item serialization, 429 at item 18, crash-after-call reconciliation, quota/duplicate-submit/concurrent-cron tests, exact-head required CI and signed same-SHA Setup/Update ZIPs; staging recovery rehearsal; remote Ollama host/proxy/scheduler preflight. No claim of LIVE/READY from mocked CI, main merge or documentation alone.

## Current deployability labels

- **CODE PROPOSED:** safe UI status and read-only reporting can be reviewed in PR/CI.
- **PRODUCTION OBSERVED:** previous `v1.0.281` evidence only; not this change.
- **BLOCKED UNTIL LIVE VERIFIED:** Ollama host/storage readiness, authenticated proxy, production queue migration, signed ZIP delivery and real remote inference.
