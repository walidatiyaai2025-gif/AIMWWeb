# Laravel AIWMWeb Production Runtime Runbook

Authority: Issue #257. Scope: `LARAVEL_AIWMWEB` infrastructure only.

## Runtime contract

The production-style reference stack is `runtime/docker-compose.yml`: Nginx/PHP-FPM, MySQL 8.4, Redis, Laravel queue workers, one scheduler, and an optional disposable WordPress/MySQL/WP-CLI profile. The image build always runs the merged Vite production build. When the React/TypeScript worker is integrated, the same stage builds that frontend without changing this runtime contract.

The Connector packager consumes only the canonical merged source path `variants/laravel-aiwmweb/connector/aimw-connector`. Until that source lands from its owning feature work, Connector packaging/install/pairing reports `BLOCKED_SOURCE`; this infrastructure does not copy or fork the feature implementation.

## Environment and secrets

Use `runtime/.env.example` as a schema, not as credentials. `APP_KEY`, application/root DB passwords, WordPress DB passwords and deterministic disposable WordPress admin password are intentionally blank. Provider keys are optional placeholders and must never be committed. The API container is not published directly; Nginx is the ingress, so the reference container network may trust its internal proxy hop. Restrict `TRUSTED_PROXIES` to concrete proxies in non-container deployments.

Generate a Laravel key non-interactively from an installed backend with `php artisan key:generate --show`, then inject it through the deployment secret mechanism.

## Boot and migrations

From `variants/laravel-aiwmweb`:

```sh
cp runtime/.env.example runtime/.env
# Fill blank secret values outside version control.
docker compose --env-file runtime/.env -f runtime/docker-compose.yml build
docker compose --env-file runtime/.env -f runtime/docker-compose.yml up -d mysql redis
docker compose --env-file runtime/.env -f runtime/docker-compose.yml run --rm api php artisan migrate --force
docker compose --env-file runtime/.env -f runtime/docker-compose.yml up -d api web worker scheduler
```

For production upgrades, back up the database first, run `php artisan migrate --force` exactly once from the candidate image, and do not run `migrate:fresh`. `migrate:fresh`, rollback/forward migration, FK/index/tenant-unique and transaction checks are CI acceptance only.

## Redis and workers

Production defaults are Redis-backed cache, sessions and queue. Tenant cache/lock keys retain the Tenant Core `tenant:<id>:` namespace. The reference worker uses three tries, bounded backoff, 120-second job timeout and a 3600-second max worker lifetime. Failed jobs use the database UUID driver.

Scale workers explicitly, for example:

```sh
docker compose --env-file runtime/.env -f runtime/docker-compose.yml up -d --scale worker=2 worker
```

Use `php artisan queue:restart` for a graceful code reload. Inspect/retry failures with Laravel `queue:failed` and `queue:retry`. A non-container Supervisor example with two worker processes is in `runtime/supervisor/laravel-aiwmweb.conf`.

## Scheduler

Run exactly one `php artisan schedule:work` service per logical deployment. Scheduled heartbeat uses a Redis-backed one-server mutex plus `withoutOverlapping`; readiness fails if the heartbeat becomes stale. Sensitive feature schedules must preserve `withoutOverlapping`/`onOneServer` semantics when integrated.

## Health

`GET /health/live` proves only that the application process can answer. `GET /health/ready` checks the application, MySQL, Redis, writable runtime storage, Redis queue connectivity and scheduler freshness. External WordPress does not participate in basic liveness/readiness, so a managed-site outage cannot make the platform process appear dead.

Request and correlation IDs are propagated through `X-Request-ID` and `X-Correlation-ID`. Runtime logs use structured JSON on stderr and redact common password/token/secret/API-key fields. Request bodies, query strings, authorization headers and cookies are not logged by the correlation middleware.

## Disposable WordPress

The WordPress profile is destructive by design and must never point at a real site. Set the disposable admin/DB values in `runtime/.env`, then:

```sh
runtime/scripts/wp-reset.sh
```

The reset removes only the named WordPress runtime containers/volumes, installs WordPress, creates the deterministic CI admin and a probe post, creates an application password, performs an authenticated real REST content read, and installs the canonical Connector ZIP when source exists. With `AIMW_PAIRING_TOKEN` set after the Laravel pairing runtime is integrated, it also calls the Connector pair route. Set `AIMW_REQUIRE_CONNECTOR=1` in final convergence CI to make missing Connector source a hard failure.

## Connector artifact

```sh
runtime/scripts/package-connector.sh
```

The ZIP contains the plugin folder and `AIMW-CONNECTOR-MANIFEST.json` recording plugin version, exact repository SHA, canonical source path and build UTC. Artifact generation intentionally fails with exit code 3 when canonical Connector source is absent.

## Deployment flow

`runtime/scripts/deploy.sh` is Linux/container friendly and non-interactive. It installs production Composer dependencies, performs the Vite build, applies forward migrations, caches configuration/views, executes one scheduler pass, restarts workers gracefully and optionally verifies `/health/ready` via `HEALTH_READY_URL`.

Route caching is intentionally not forced while the merged application still contains closure routes; configuration/view caching remains active. Once convergence removes closure routes, the Lead may add `route:cache` as a required gate.

Rollback is application-image-first: retain the previous immutable image and DB backup before migration. If a post-deploy health gate fails, restore the previous image. Database rollback is not automatic because destructive/down migrations require operation-specific review; restore the pre-deployment DB backup when a forward migration cannot safely remain.

## Performance sanity

The runtime imposes bounded PHP request/body/upload/time limits, Redis queue retry/timeout/lifetime limits, and no-eviction Redis behavior. CI runs the existing static acceptance scan for obvious unbounded operations plus real MySQL/Redis/queue/scheduler smoke. These are sanity gates, not benchmark claims.

## Artifacts

CI produces, when source is available: Connector ZIP, Vite `public/build`, `runtime-manifest.json`, and a deployment/runbook bundle. Every runtime manifest records the exact source SHA and pinned reference images. Feature-owned source is never synthesized by this worker merely to make an artifact green.
