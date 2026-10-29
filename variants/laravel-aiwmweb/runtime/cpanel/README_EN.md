# Laravel AIWMWeb cPanel candidate

This archive is a candidate until the exact source SHA passes all release gates.
No production deployment was performed. Ollama and all AI execution start disabled.

## Fresh installation

1. In cPanel create an empty MySQL 8 / MariaDB 10.11+ database and user with migration privileges. Select PHP 8.3+ with pdo_mysql, mbstring, openssl, fileinfo, tokenizer, ctype, XML/DOM, curl, session, zip and GD. Composer and Node are build-time tools only.
2. Upload and extract this ZIP in your account HOME, outside public_html. The result is AIMWWeb/app (private) and AIMWWeb/public (public). Set the domain document root to AIMWWeb/public. Never point it at AIMWWeb or app. If your host cannot set this root, stop: a workaround is not verified.
3. Configure HTTPS. Enable cPanel Directory Privacy on AIMWWeb/public before visiting anything. The installer requires hosting-owner authentication; the ZIP contains no reusable setup password.
4. Open https://YOUR-DOMAIN/install.php. Preflight verifies PHP, permissions, private layout and payload hashes. Enter APP_URL, database credentials and first administrator details. Use a password of at least 12 characters.
5. The installer creates a private .env/APP_KEY exactly once, runs migrations, creates the Owner membership/permissions and validates the login route and password hash. It locks itself after success. Existing databases are never silently reseeded. /setup is disabled in this profile.
6. Log in. Remove Directory Privacy after installation; verify install.php and /setup both return 404. Check that /.env, /vendor/autoload.php, /storage/logs/laravel.log, /app and backup URLs cannot be read remotely. Verify secure/HttpOnly session cookies over HTTPS. Keep app and backups outside the document root; only storage and bootstrap/cache need runtime writes. Restrict .env to account owner read/write.
7. Test a disposable WordPress site's REST/Connector authentication, content read/edit, draft, approval and execution receipt. Never use a production site for the initial rehearsal.

## Runtime defaults and hosting preflight

Database-backed session/cache/queue drivers work without Redis. No Supervisor, Horizon, schedule:work or daemon is installed. AI, cron and preflight admission remain false. Inference availability is not required to install or log in.

Before enabling cron, hosting support must confirm: CLI PHP matches the web version, flock and GNU timeout exist, pcntl/process termination works, private writable lock files work, memory/process limits allow a bounded 110-second invocation, and database transactions/row locks work. Test two simultaneous cron invocations; only one may run. Queue backlog must remain bounded. Review failed_jobs rather than blindly retrying remote mutations.

Configure one cPanel Cron entry using the supplied cron.sh, with AIMW_APP_ROOT pointing to the private app directory and AIMW_PHP_BIN pointing to PHP CLI. Example (replace paths in the panel, not application code):

```
* * * * * AIMW_APP_ROOT=/home/ACCOUNT/AIMWWeb/app AIMW_PHP_BIN=/usr/local/bin/php /bin/bash /home/ACCOUNT/AIMWWeb/cron.sh
```

The shared nonblocking flock encloses one hard timeout of 110 seconds (+5 seconds kill grace). Queue worker: once, max-jobs=1, tries=1, timeout=45, memory=128 MiB. Provider HTTP timeout <=30 seconds. The AI durable runner processes at most one action. Process memory cap is 192 MiB. An expired AI lease is never stolen: ambiguous requests require reconciliation and no automatic replay.

Set CPANEL_PREFLIGHT_PASSED=true and CPANEL_CRON_ENABLED=true only after that rehearsal. Keep CPANEL_AI_ENABLED=false until provider/model readiness and budgets are validated. Ollama must remain disabled until the separate infrastructure owner recovers and health-validates it. Do not probe or restart it as part of installation. If 30-second inference cannot work within host limits, keep AI off and route a separately authenticated external runner project; no always-on cPanel worker is supported.

Default admission caps: 100 calls/day, 100,000 reserved tokens/day, estimated USD 1/day, USD 0.10 reserved/call. These are conservative admission reservations, not a provider billing guarantee. Configure caps only within your actual provider pricing/budget. A chargeable timeout or process crash becomes needs_reconciliation and stops the global circuit. Never clear it or replay without proving the previous process ended and reconciling the provider request/result.

## Upgrade and recovery

Upgrades are a controlled maintenance procedure, not a fresh installation. Never run install.php over an existing database and never regenerate APP_KEY.

1. Verify the candidate SHA-256 and every MANIFEST file hash. Confirm exact-SHA CI acceptance and database compatibility. Check free storage for a full backup and a separate extracted candidate.
2. Put the old application in maintenance mode and disable cron. Back up the complete old directory, private .env, credentials, storage/uploads and a consistent full database export through cPanel Backup/phpMyAdmin. Verify the database export by restoring it into a disposable database and checking representative data and authentication. Keep backups private.
3. Extract the candidate to a different private directory. Copy the original .env and runtime storage into its app directory, preserving APP_KEY, uploads, installation lock and credentials. Do not overwrite the original application.
4. Confirm migration filenames and hashes match the accepted manifest. Run PHP artisan migrate --force using cPanel Terminal only after the backup restoration test. Never run migrate:fresh, reset, seed, queue:clear or rollback indiscriminately. Switch the domain document root to the candidate public directory only after validation.
5. Verify login, tenant isolation, site access, uploads, disabled AI status, WordPress reads and approvals before re-enabling bounded cron.
6. If validation fails, keep cron off, return the document root to the old application and preserve failure evidence. If the schema changed incompatibly, restore the tested database backup under maintenance control before reopening traffic. Automatic database rollback is NOT implemented or claimed. Do not use down migrations to delete durable AI evidence.

Record source SHA, backup locations, database export checksum, installed version and validation results in a private deployment log. Actual cPanel process policy, Directory Privacy propagation, HTTPS routing and limits require host-side verification. A local Linux test is not proof of actual cPanel compatibility.
