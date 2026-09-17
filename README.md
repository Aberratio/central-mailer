# Central Mailer

A lightweight PHP backend service for centralized, queued email sending from multiple applications. Messages are stored in MySQL/MariaDB and sent by background workers. Standard messages use the primary SMTP account; `technical` messages use a separate FIFO queue and a Gmail SMTP account.

Stack: PHP 8.2+, Slim Framework, MySQL/MariaDB, PHPMailer, vlucas/phpdotenv, Monolog.

## Requirements

- PHP 8.2+, Composer
- MySQL 8.0+ or MariaDB 10.6+
- SMTP access (e.g. CyberFolks/SeoHost)
- A Gmail account with 2-Step Verification and an App Password (for technical messages)
- PHP extensions: `pdo`, `pdo_mysql`, `json`, `mbstring`, `fileinfo`

## Installation

```bash
composer install
cp .example.env .env
```

Set database access, API keys, SMTP settings, and sending limits in `.env`, then validate:

```bash
php scripts/validate-config.php
```

For production: `APP_DEBUG=false`, HTTPS `APP_URL`, explicit CORS origins, `APP_DOCS_ENABLED=false`, `APP_DOCS_PUBLIC=false`, `SMTP_DEBUG_LEVEL=0`. API keys and `BACKUP_ENCRYPTION_KEY` must be at least 32 characters.

### Key `.env` variables

| Variable | Purpose |
|---|---|
| `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_CORS_ORIGIN` | App environment and CORS |
| `APP_DOCS_ENABLED` / `APP_DOCS_PUBLIC` | Swagger UI availability (disable in production) |
| `DB_*` | MySQL/MariaDB connection |
| `API_KEY_APP_A`, `API_KEY_APP_B` | Legacy per-app API keys (seeded into `email_clients`) |
| `SMTP_*` | Primary SMTP account (standard queue) |
| `GMAIL_SMTP_*`, `GMAIL_FROM_EMAIL`, `TECHNICAL_EMAIL_FALLBACK_TO_STANDARD` | Gmail SMTP account (technical queue) and fallback behaviour |
| `EMAIL_RATE_LIMIT_*`, `EMAIL_ENQUEUE_RATE_LIMIT_*`, `GMAIL_RATE_LIMIT_*` | Global, enqueue, and Gmail sending rate limits |
| `EMAIL_WORKER_BATCH_SIZE`, `EMAIL_WORKER_SLEEP_SECONDS`, `EMAIL_PRIORITY_AGING_SECONDS` | Worker scheduling |
| `EMAIL_BATCH_MAX_RECIPIENTS`, `EMAIL_ATTACHMENT_*` | Batch and attachment limits |
| `EMAIL_MAX_QUEUED_PER_CLIENT`, `EMAIL_MAX_ACTIVE_ATTACHMENT_BYTES_PER_CLIENT` | Per-client caps |
| `EMAIL_DATA_RETENTION_DAYS` | Retention cleanup window |
| `BACKUP_RETENTION_DAYS`, `BACKUP_ENCRYPTION_KEY` | Database backups |
| `UNSUBSCRIBE_SECRET(_PREVIOUS)` | HMAC secret for one-click unsubscribe links |
| `LOG_LEVEL`, `LOG_DIR`, `LOG_MAX_FILES` | Logging (empty `LOG_DIR` uses `storage/logs`) |

The `email_clients` table is the source of truth for authentication, activation, queue weight, and per-client rate limits — the backend never trusts the `sourceApp` field from the request body. Legacy `.env` keys are auto-seeded/rotated into `email_clients` on startup.

## Database and migrations

```bash
mysql -u root -p -e "CREATE DATABASE central_mailer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php scripts/run-migrations.php
```

Other modes: `--dry-run`, `--baseline` (existing DB already at current schema, no `schema_migrations` table), `--baseline --baseline-through=<file>` (older known schema, run newer migrations normally), `--adopt-existing` (DB was migrated manually or a first tracked run stopped on duplicate columns/tables — it records already-present migrations and runs the rest).

Example client:

```sql
INSERT INTO email_clients
  (source_app, api_key_hash, active, queue_weight, rate_limit_count, rate_limit_window_minutes, created_at, updated_at)
VALUES
  ('billing', SHA2('replace-with-long-random-key', 256), 1, 2, 50, 15, NOW(), NOW());
```

Rotate a key by updating `api_key_hash`. A larger `queue_weight` gives a client a larger share of sending capacity.

## Running locally

```bash
composer serve             # API — or: php -S 0.0.0.0:8080 -t public public/router.php
composer worker             # standard worker — or: php bin/worker.php
composer technical-worker   # Gmail worker — or: php bin/technical-worker.php
```

Run exactly one technical worker to preserve strict FIFO ordering for `technical` messages.

## API

Swagger UI: `http://localhost:8080/docs` (raw spec at `/openapi.json`, both unauthenticated). All other endpoints require an `X-API-Key` header.

| Method | Path | Purpose |
|---|---|---|
| POST | `/emails` | Queue one email (optional `Idempotency-Key`, attachments, `priority`) |
| POST | `/emails/batch` | Queue one email per recipient, sharing common subject/body |
| GET | `/emails/{id}` | Get status of one message (own application only) |
| GET | `/emails/{id}/events` | Status/event history for one message |
| GET | `/emails` / `/emails/unsent` | Paginated listing (`limit`, `offset`, `hasMore`) |
| GET | `/emails/diagnostics` | Per-client queue health: status counts, oldest unsent, next retry, rate-limit usage, worker heartbeats |
| POST | `/admin/emails/{id}/requeue` | Requeue a quarantined (`unknown`) message |
| POST | `/admin/suppressions` | Manage the suppression list |
| GET | `/admin/status` | Worker heartbeats, limits configuration |
| GET | `/admin/stats/sent` | Sent-message statistics by app/worker/time bucket |
| GET | `/health` | Liveness (`?strict=1` returns 503 on stale worker heartbeats) |

`Idempotency-Key` is optional but recommended: a repeated request with the same key and application returns the existing message (`200`); the same key with different content returns `409`.

Attachments (`attachments[]`) are optional, validated by real MIME type, and stored under `storage/attachments` until the message is terminal. Set `inline: true` with a `contentId` to embed an image via `src="cid:..."` — inline items must keep their `filename` (Gmail requirement) and should not also be sent as a separate regular attachment.

Every message has a `category`: `transactional` (default for single sends) or `marketing` (default for batches). Marketing mail gets `List-Unsubscribe` headers once `UNSUBSCRIBE_SECRET` is configured. Installations that only send transactional mail can set `EMAIL_BATCH_DEFAULT_CATEGORY=transactional` to skip the unsubscribe machinery entirely.

## Queue and worker behaviour

Message statuses: `pending` → `processing` → `sent` (SMTP accepted it, not proof of mailbox delivery) or `retry`/`failed`; a message whose processing lease expires mid-send is quarantined as `unknown` rather than retried, to avoid duplicate delivery.

Scheduling order: effective high priority → weighted fairness between clients → `created_at`. Normal messages older than `EMAIL_PRIORITY_AGING_SECONDS` get effective high priority so they can't starve. The technical worker ignores weights/aging and sends strictly the oldest non-terminal `technical` message; when `TECHNICAL_EMAIL_FALLBACK_TO_STANDARD=true`, a message that exhausts its Gmail attempts falls back to the standard queue as `normal`.

Retries use exponential backoff (60s doubling to 3600s) with jitter. Permanent SMTP rejections (5xx, except 552) fail immediately; hard bounces (`5.1.*`, `5.2.1`) also add the address to the suppression list. The suppression list blocks sending to dead/opted-out addresses at both enqueue and send time.

The sender name, reply-to, logo, and footer are configured once in `src/Email/EmailBrandConfig.php` and applied by the worker at send time.

## Monitoring

- `scripts/monitor-queue.php` (cron, every 5-15 min): checks heartbeats, failures, queue latency, and quarantined rows; alerts via `ALERT_EMAIL` / `ALERT_WEBHOOK_URL`.
- `scripts/process-bounces.php` (optional, cron): polls the Return-Path mailbox via IMAP for async DSN bounces and suppresses hard-bounced addresses.
- `scripts/cleanup-retention.php` (cron): purges data older than `EMAIL_DATA_RETENTION_DAYS`.
- The admin panel (`/admin.html`) shows queue status, sent-message statistics (`GET /admin/stats/sent`), and configured limits (`GET /admin/status`).

## Deployment

`.github/workflows/deploy.yml` deploys to the `staging` or `production` GitHub environment: a push to `main`/`master` deploys to staging with migrations enabled; production requires a manual run with `confirm_production=DEPLOY_PRODUCTION`. The workflow publishes into a symlinked `releases` directory (or via `rsync` on shared hosting), runs migrations, checks health, and rolls back the symlink on failure. Database backups are encrypted with `BACKUP_ENCRYPTION_KEY`.

Required secrets (per environment): `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `BACKEND_REMOTE_DIR`, `BACKEND_ALLOWED_ROOT`, `BACKEND_BACKUP_DIR`, `API_URL`.

For production workers, run the worker and technical-worker as systemd services or via cron with `flock`/`timeout`; see `bin/worker.php` and `bin/technical-worker.php`. Workers shut down gracefully on SIGTERM, a stop file (`storage/worker.stop`), or `EMAIL_WORKER_MAX_RUNTIME_SECONDS`.

## Switching SMTP providers

The sending layer is abstracted behind `CentralMailer\Email\EmailProviderInterface`; the queue, retries, and statuses don't know about the specific provider. To add a new provider: implement the interface (e.g. `BrevoEmailProvider`), add its `.env` variables, and wire it into the provider factory in `public/index.php` and `bin/worker.php`.

## Security

- API keys are stored as SHA-256 hashes; SMTP credentials stay in `.env`.
- No CC/BCC; `from` always comes from `.env`; applications are isolated by `sourceApp`.
- Production startup refuses debug mode, wildcard CORS, public docs, non-HTTPS URLs, weak API keys, and invalid SMTP encryption.
- Configure reverse-proxy/web-server rate limiting for invalid API-key attempts, and cap the request body size (`APP_MAX_REQUEST_BODY_BYTES`) at the web-server level too.

## Testing

Run the test suite with Composer's configured test script (see `composer.json`). Validate configuration before deploying with `php scripts/validate-config.php`.

## Troubleshooting

- `401 Invalid or missing API key`: check the `X-API-Key` header and configured client keys.
- `Email not found`: message doesn't exist, or belongs to a different application's API key.
- Nothing sending: confirm the worker is running and check for rows stuck in `retry` with a future `next_attempt_at`.
- Rate limit blocking sends: check global/client limits and `email_rate_limit_reservations`.
- SMTP errors: verify host, port, `SMTP_SECURE`, login, and password.
