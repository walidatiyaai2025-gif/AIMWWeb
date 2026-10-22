# Laravel AIWMWeb production-style runtime

This directory is the reproducible runtime owned by Issue #257 Worker E. It runs the merged Laravel variant without importing feature-worker source.

## Services

- Nginx + PHP-FPM Laravel API/application
- MySQL 8.4 LTS
- Redis for cache, queues, sessions, distributed locks and scheduler mutexes
- Redis queue worker(s)
- single scheduler runtime
- optional disposable WordPress + WordPress MySQL + WP-CLI profile
- Connector ZIP packaging/install hooks for the canonical source path `../connector/aimw-connector`

Copy `.env.example` to an untracked `.env`, inject unique values for all blank secrets, then run:

```sh
docker compose --env-file runtime/.env -f runtime/docker-compose.yml up -d mysql redis api web worker scheduler
docker compose --env-file runtime/.env -f runtime/docker-compose.yml --profile wordpress up -d
```

Do not commit runtime `.env` or generated artifacts. See `../docs/PRODUCTION_RUNTIME_RUNBOOK.md` for deployment, scaling, health, WordPress reset and rollback details.
