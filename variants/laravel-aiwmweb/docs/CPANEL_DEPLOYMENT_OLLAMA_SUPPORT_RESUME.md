# Laravel AIWMWeb — cPanel deployment & DeluxHost Ollama support resume runbook

> **Checkpoint recorded: 2026-10-30 (Asia/Kuwait).** This is a **continuation snapshot**, not a production-ready certification or a substitute for live GitHub/host evidence. Read this first when work resumes; then refresh every dynamic fact.
>
> **Owner decision:** prepare the Laravel application/package while DeluxHost investigates the AI VPS disk I/O issue. Do **not** deploy blindly, restart/benchmark the affected VPS, or enable remote AI until its storage health is established.

## 1. Scope and traceability

- Project: `AIMWWEB`; product family: `PRODUCT_FAMILY`; **variant** `LARAVEL_AIWMWEB`.
- Repository: [walidatiyaai2025-gif/AIMWWeb](https://github.com/walidatiyaai2025-gif/AIMWWeb).
- Allowed variant boundary: `variants/laravel-aiwmweb/**`; **do not modify ASP.NET or shared CORE**.
- PCC source of this snapshot: `walidatiyaai2025-gif/project-control-center@076eeb0ed3fe75200d907b111803a210e5c054c5` (refresh PCC `main` at resumption); authority [#257](https://github.com/walidatiyaai2025-gif/AIMWWeb/issues/257).
- cPanel/AI implementation and acceptance gate: [#1384](https://github.com/walidatiyaai2025-gif/AIMWWeb/issues/1384). Implementation [PR #1385](https://github.com/walidatiyaai2025-gif/AIMWWeb/pull/1385).
- This documentation-only continuation task: [#1386](https://github.com/walidatiyaai2025-gif/AIMWWeb/issues/1386).
- Integration target `worker/laravel-aiwmweb-closure-composition` checked at `0d35b4b44a09aaef0f4b1701b8f2336f5e2cf1e8` after PR #1385 merged.
- **Important lineage:** repo `main` was separately observed at `9aee452bcf1d0f766da7b1718b79c73cc0020eec`; **do not assume the Laravel composition changes are already merged to main**. Release source must be the explicitly accepted, verified final integration SHA.
- This runbook describes operational follow-up. It must not amend PCC constitutional rules or supersede repository `AGENTS.md`.

## 2. Verified status at this checkpoint

| Area | Last verified / reported state | Remaining gate |
|---|---|---|
| Durable AI / bounded cPanel runtime | PR #1385 **merged** to Laravel composition. Batches, serialization, quotas, pause/resume, ambiguous-charge reconciliation and no-daemon cron profile implemented. | Continue #1384; do not claim acceptance solely from merge. |
| Automated tests | PR head `1d5c2a7677de7e1db545216c2fb78bb2d45a6ad9`: required PR gates green. PR reports MySQL **2021 tests / 19358 assertions**; frontend **1227 tests** and build; 50-item, 429-at-18, process-concurrency and double-cron tests. | Verify exact **final merged/release SHA** CI, not only PR head. |
| Installer | Production-dependency/compiled-frontend candidate archive built in CI; manifest inspection reported 7606 payload hashes, no `.env` in archive. | Retrieve and verify accepted-source artifact and its SHA-256; run actual-host preflight, staging and backup/restore rehearsal. |
| Release classification | **CONTROLLED STAGING ONLY**. No cPanel deployment yet. | Actual hosting acceptance + owner go/no-go required. |
| AI runtime | Default `CPANEL_AI_ENABLED=false`, `CPANEL_CRON_ENABLED=false`, `CPANEL_PREFLIGHT_PASSED=false`. | Never enable until host and provider/runner readiness gates pass. |
| DeluxHost VPS | Storage issue **OPEN, support reply pending**. | Host investigation and recovery evidence before any heavy inference. |

**Existing implementation documents (source of installation steps):**
- [runtime/cpanel/README_AR.md](../runtime/cpanel/README_AR.md)
- [runtime/cpanel/README_EN.md](../runtime/cpanel/README_EN.md)
- [runtime/cpanel/cron.sh](../runtime/cpanel/cron.sh)
- [backend/config/cpanel.php](../backend/config/cpanel.php)

## 3. External blocker: DeluxHost AI VPS

Observed (do not publish host IP, login details, customer identifier, ticket credentials or keys):
- DeluxHost service **Z-4**: Ubuntu 24.04, **2 vCPU**, **8 GB RAM**, roughly **80 GB disk**. Ollama `0.40.6` was responding on `127.0.0.1:11434`; installed models `qwen3:1.7b`, `qwen3:4b`, `qwen3:4b-instruct`.
- Before diagnosis, `qwen3:1.7b` benchmark: 180 tokens in 32.7 s, 7.64 generated tokens/s; content quality not acceptable for unattended Arabic publishing.
- `qwen3:4b` and `qwen3:4b-instruct` generation attempts were slow/unreliable on this 2-vCPU host; the latter exceeded a 240-second request timeout. **These are not evidence of production capacity.**
- Ollama service CPUQuota `150%` was confirmed active; free RAM remained high, swap negligible.
- Critical host-level I/O stall measured: `/proc/pressure/io some avg10=99.59`, `full avg10=98.94`; `jbd2/vda1-8` blocked in `wait_on_buffer`, journald in `jbd2_log_wait_commit`, several flush workers in `rq_qos_wait`.
- Strong evidence of storage-path saturation/stall, **not proof of which hypervisor device, physical drive, quota or operator caused it**. Support has been contacted/planned; ticket identifier and formal reply have **not** been provided in this record.

### Until DeluxHost responds
1. No Ollama model downloads, benchmarks, training, inference load tests or synthetic I/O stress.
2. No forced VPS reboot or mounted-root `fsck`; preserve backups and coordinate any recovery/migration with host support.
3. No public bind of Ollama port 11434 or unauthenticated proxy.
4. Laravel packaging, CI, documentation and **non-AI staging preparation** can proceed independently.

### When support replies
1. Record the support ticket ID and diagnosis **privately** (do not commit credentials or personal data).
2. Ask for node-level storage latency/IOPS/error/throttling evidence and whether storage repair/migration is required; request data integrity and backup confirmation.
3. After provider-confirmed mitigation, run **short, low-impact, bounded** health checks during a safe window: `/proc/pressure/io`, `vmstat`, number/state of D processes, filesystem health/host confirmation, `ollama /api/version`. No large recursive journals or disk stress tests.
4. Require pressure materially lower and stable across multiple samples, no persistent blocked `jbd2`/writeback tasks, and healthy hosting/storage confirmation before inference resumes. Avoid an invented numerical guarantee from one sample.
5. Start with a **single short** `qwen3:1.7b` request under low load. Benchmark `4b-instruct` only if host stays healthy; compare Arabic output accuracy and real timeouts. Keep execution serialized.
6. Because the cPanel profile caps provider HTTP at **30 s**, a 240-second CPU Ollama inference **cannot** be treated as a direct cPanel HTTP call. If response time does not meet this budget, design and separately test a secure external AI runner/async polling/checkpoint path rather than raising cPanel process limits or blocking PHP-FPM.
7. Remain review/draft-first for WordPress SEO content; no automatic publishing of unverifiable claims.

## 4. Work that can be completed while waiting

### Gate A — Reconcile repository and artifact provenance
- Refresh PCC routing, `AGENTS.md`, composition HEAD, PRs, Issue #1384 and ownership/leases. Continue existing canonical work; no duplicate #1384 implementation branch.
- Verify required workflow conclusions on the **actual merged/accepted SHA**, and parity/tenant/security checks relevant to this Laravel release.
- Retrieve CI-generated cPanel ZIP from the accepted SHA; verify filename, source SHA, SHA-256 and every manifest file hash; ensure no `.env`, hardcoded provider secrets or private runtime data.
- Never ship the older forbidden kit source `6753e857404aab5636751d592062715da5ff9510` for live AI.

### Gate B — cPanel preflight **before installation**
- Confirm PHP CLI **and web PHP 8.3+** and extensions specified in the cPanel README; MySQL 8 or MariaDB 10.11+, DB transactions/row locking, file permissions, storage and backup space.
- Verify hosting account may set document root to **`AIMWWeb/public`**, with `app/`, `.env`, secrets, uploads, logs and backups outside public web root. **Stop** if the host cannot support a verified private layout; do not use an unreviewed `public_html` workaround.
- Verify HTTPS and temporary cPanel Directory Privacy before exposing the installer. Protect setup credentials; do not include credentials in archives.
- Confirm server-side process, `flock`, GNU `timeout`, `pcntl`, Cron and PHP CLI paths, memory/CPU/job policies. Do not enable Cron merely because a panel offers it.

### Gate C — controlled staging rehearsal
- Use a disposable staging domain and database. Install from the accepted ZIP **without Composer/npm in cPanel**. Check payload integrity, installer lock, `APP_KEY` persistence, first Owner authentication, DB migrations and 404/blocked private endpoints after setup.
- Verify runtime security, HTTPS/session cookies, tenant isolation, a disposable WordPress site journey, drafts/approval and audit receipts with **AI disabled**.
- Confirm no permanent `queue:work`, Supervisor, Horizon, `schedule:work`, or default Cron execution.
- Before any upgrade, create and **test restore** of full DB, `.env`/APP_KEY, uploads/storage and application backup. Verify recovery/rollback instructions; do not assume database automatic rollback.
- Exercise a double Cron invocation only after provider-confirmed host support for bounded processes; no overlap, at most one queue job/AI action per tick, queue admission limits respected. If prerequisites unavailable, leave Cron disabled and record as blocked.

### Gate D — owner deployment decision
**No production rollout until** exact-SHA CI, accepted ZIP/hash, hosting preflight, secure private layout, staging functional checks, verified backup restore, and owner authorization are all evidenced.
- Production may launch **with AI disabled** if the unrelated Ollama server is still under maintenance and the app's non-AI gates pass.
- Keep `CPANEL_AI_ENABLED=false` and bounded Cron off until explicit separate provider/runtime approval.
- Only close #1384 after its full acceptance, including external host gates, is truly met. A merged PR or green mocked tests alone does not close it.

## 5. Post-host recovery: SEO content engine plan (separate from installer launch)

1. One shared Ollama model configured centrally; separate tenant/site-scoped WordPress knowledge, indexed content, brand voice and internal links. No cross-site or cross-tenant retrieval.
2. Prefer **RAG/site memory + feedback**, not CPU fine-tuning on a 2-vCPU VPS. Use WordPress content and verified Search Console inputs where connected.
3. Site discovery -> SEO audit -> content opportunity -> short structured generation -> grounded validation -> human approval -> WordPress draft -> tracked results.
4. AI tasks are **durable 1–100 item batches**; max one globally in-flight provider request in the small-host profile; each result/checkpoint is committed before starting the next; no ambiguous paid-call auto-replay.
5. Do not automatically publish low-quality Arabic results, invent local facts, or assume SEO uplift is caused solely by an AI rewrite.
6. If real inference consistently exceeds cPanel provider timeout, design an authenticated pull-based **remote AI runner** with idempotency, leases/fencing, usage/budgets and one-item checkpoints; validate as a separate scoped task before enabling it.

## 6. Resume checklist for the next session

- [ ] Read this file; fetch **live** PCC `main`, repository `main`, Laravel composition HEAD and open PRs. Historical SHAs above are snapshots.
- [ ] Read latest comments/closure status of [#1384](https://github.com/walidatiyaai2025-gif/AIMWWeb/issues/1384); confirm [#1385](https://github.com/walidatiyaai2025-gif/AIMWWeb/pull/1385) is still part of accepted lineage.
- [ ] Verify post-merge exact-head CI and retrieve/source-check the approved ZIP rather than reusing arbitrary unmerged artifacts.
- [ ] Ask owner for **DeluxHost support response** and for the **cPanel provider's PHP/DB/document-root/Cron capabilities**. Do not request passwords or keys in chat.
- [ ] If support not fixed: proceed with non-AI cPanel preflight/staging only. Avoid the AI VPS.
- [ ] If support fixed: verify I/O recovery, then small isolated Ollama health/quality/capacity tests and decide whether external runner is mandatory.
- [ ] Only after all release gates, report a truthful **READY / CONTROLLED STAGING / BLOCKED** classification; obtain explicit permission for production deployment.

## 7. Stop conditions and evidence to report

Stop and report a specific blocker on any failure of: tenant isolation, post-merge CI, artifact provenance, private document root, HTTPS and installer protection, backup restore, host-safe Cron, provider serialization or storage recovery.

For every continuation report **exact source SHA, PR/issue references, CI URLs, artifact checksum, staging tests, hosting limitations, external-support decision and next owner action**. Do not claim completion from a local mock or an unverified hosting assumption.
