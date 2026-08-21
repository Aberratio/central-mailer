# Central Mailer

A lightweight PHP backend service for centralized, queued email sending from multiple applications. The API stores messages in MySQL/MariaDB. Standard messages use the primary SMTP account, while `technical` messages use a separate FIFO queue and Gmail SMTP account.

Stack: PHP 8.2+, Slim Framework, MySQL/MariaDB, PHPMailer, vlucas/phpdotenv, Monolog.

## Requirements

- PHP 8.2 or newer
- Composer
- MySQL 8.0+ or MariaDB 10.6+
- SMTP access, for example CyberFolks/SeoHost
- A Gmail account with 2-Step Verification and an App Password for technical messages
- PHP extensions: `pdo`, `pdo_mysql`, `json`, `mbstring`, `fileinfo`

## Installation

```bash
composer install
cp .example.env .env
```

Set the correct values in `.env`: database access, API keys, SMTP settings, and sending limits.

## `.env` configuration

Key variables:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_CORS_ORIGIN=*
APP_MAX_REQUEST_BODY_BYTES=12000000
APP_DOCS_ENABLED=true
APP_DOCS_PUBLIC=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=central_mailer
DB_USERNAME=root
DB_PASSWORD=secret
DATABASE_DUMP_BIN=

API_KEY_APP_A=change-me-app-a
API_KEY_APP_B=change-me-app-b

SMTP_HOST=h18.seohost.pl
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=kontakt@example.com
SMTP_PASSWORD=secret
SMTP_FROM_EMAIL=kontakt@example.com
SMTP_DEBUG_LEVEL=0

GMAIL_SMTP_USER=developer@gmail.com
GMAIL_SMTP_APP_PASSWORD=google-app-password
GMAIL_SMTP_PORT=587
GMAIL_SMTP_SECURE=tls
GMAIL_FROM_EMAIL=developer@gmail.com
TECHNICAL_EMAIL_FALLBACK_TO_STANDARD=true

EMAIL_RATE_LIMIT_COUNT=100
EMAIL_RATE_LIMIT_WINDOW_MINUTES=15
EMAIL_RATE_LIMIT_RESERVATION_RETENTION_MINUTES=10080
EMAIL_ENQUEUE_RATE_LIMIT_COUNT=60
EMAIL_ENQUEUE_RATE_LIMIT_WINDOW_MINUTES=1
EMAIL_ENQUEUE_RATE_LIMIT_RETENTION_MINUTES=1440
EMAIL_WORKER_BATCH_SIZE=20
EMAIL_WORKER_SLEEP_SECONDS=10
EMAIL_PRIORITY_AGING_SECONDS=900
EMAIL_BATCH_MAX_RECIPIENTS=1000
EMAIL_ATTACHMENT_MAX_COUNT=5
EMAIL_ATTACHMENT_MAX_TOTAL_BYTES=5000000
EMAIL_ATTACHMENT_ALLOWED_MIME_TYPES=image/png,application/pdf
EMAIL_MAX_QUEUED_PER_CLIENT=10000
EMAIL_MAX_ACTIVE_ATTACHMENT_BYTES_PER_CLIENT=100000000
EMAIL_DATA_RETENTION_DAYS=90

BACKUP_RETENTION_DAYS=30
BACKUP_ENCRYPTION_KEY=replace-with-at-least-32-random-characters

LOG_LEVEL=info
LOG_DIR=
LOG_MAX_FILES=30
```

Leave `LOG_DIR` empty to use `storage/logs`. On a deployed server, set it only to an absolute path.
Logs rotate daily and retain `LOG_MAX_FILES` files.

On startup, legacy `.env` keys are inserted into `email_clients`, and an existing matching client key is rotated
when its configured value changes:

- `API_KEY_APP_A` -> `app-a`
- `API_KEY_APP_B` -> `app-b`

The database table `email_clients` is the source of truth for authentication, activation, queue weights, and optional per-client sending rate limits. The backend does not trust the `sourceApp` field from the request body.

For production, set `APP_DEBUG=false`, use an HTTPS `APP_URL`, configure explicit CORS origins, set
`APP_DOCS_ENABLED=false`, `APP_DOCS_PUBLIC=false`, and keep `SMTP_DEBUG_LEVEL=0`. Configured legacy API keys
and `BACKUP_ENCRYPTION_KEY` must contain at least 32 characters. Validate the environment before starting or deploying:

```bash
php scripts/validate-config.php
```

## Database and migration

Create the database:

```bash
mysql -u root -p -e "CREATE DATABASE central_mailer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

For a new database, run the initial migration:

```bash
mysql -u root -p central_mailer < database/migrations/001_create_email_queue.sql
```

For an existing database created before delivery-safety support, run:

```bash
mysql -u root -p central_mailer < database/migrations/002_add_delivery_safety.sql
```

Then run the clients, batch, attachments, and event history migration:

```bash
mysql -u root -p central_mailer < database/migrations/003_add_clients_batches_attachments_events.sql
```

Then enable the technical priority:

```bash
mysql -u root -p central_mailer < database/migrations/004_add_technical_priority.sql
```

Then add API enqueue rate limiting:

```bash
mysql -u root -p central_mailer < database/migrations/005_add_enqueue_rate_limit.sql
```

Then add inline attachment content IDs:

```bash
mysql -u root -p central_mailer < database/migrations/006_add_inline_attachment_content_id.sql
```

Then add worker heartbeats and queue observability indexes:

```bash
mysql -u root -p central_mailer < database/migrations/007_add_worker_heartbeats_observability.sql
```

Then add the `unknown` quarantine status (duplicate-delivery protection):

```bash
mysql -u root -p central_mailer < database/migrations/008_add_unknown_status.sql
```

Then add message categories and the suppression list:

```bash
mysql -u root -p central_mailer < database/migrations/009_add_category_and_suppressions.sql
```

The deploy workflow uses the migration runner instead of invoking SQL files manually:

```bash
php scripts/run-migrations.php
php scripts/run-migrations.php --dry-run
php scripts/run-migrations.php --adopt-existing
php scripts/run-migrations.php --baseline
php scripts/run-migrations.php --baseline --baseline-through=004_add_technical_priority.sql
```

Use `--baseline` once for an existing database that already has the current schema but does not have the
`schema_migrations` table. Use `--baseline-through` when the database has an older known schema and newer migration
files must still be executed normally. Use the normal command for a new empty database and for later deployments.
Use `--adopt-existing` when a database was migrated manually before `schema_migrations` existed, or when a first
tracked migration run stopped on duplicate columns/tables. It records only migrations whose expected tables/columns
are already present, then runs the remaining migrations normally.

Example client configuration:

```sql
INSERT INTO email_clients
  (source_app, api_key_hash, active, queue_weight, rate_limit_count, rate_limit_window_minutes, created_at, updated_at)
VALUES
  ('billing', SHA2('replace-with-long-random-key', 256), 1, 2, 50, 15, NOW(), NOW());
```

Rotate a key by updating `api_key_hash`. A larger `queue_weight` gives the client a larger share of available sending capacity.

`EMAIL_ENQUEUE_RATE_LIMIT_*` limits accepted POST requests per client. `EMAIL_MAX_QUEUED_PER_CLIENT` limits
non-terminal messages per client, and `EMAIL_MAX_ACTIVE_ATTACHMENT_BYTES_PER_CLIENT` limits active attachment storage.

## Local run

API:

```bash
composer serve
```

or:

```bash
php -S 0.0.0.0:8080 -t public public/router.php
```

Worker:

```bash
composer worker
```

or:

```bash
php bin/worker.php
```

The worker runs in a loop and sleeps for `EMAIL_WORKER_SLEEP_SECONDS` seconds between cycles.

Technical Gmail SMTP worker:

```bash
composer technical-worker
```

or:

```bash
php bin/technical-worker.php
```

Run exactly one technical worker to preserve strict FIFO ordering.

## Endpoints

Swagger UI documentation is available after starting the API at:

```text
http://localhost:8080/docs
```

The raw OpenAPI 3.0 specification is available at:

```text
http://localhost:8080/openapi.json
```

Documentation endpoints do not require `X-API-Key`. The API endpoints described below still require authorization with the `X-API-Key` header.

### Add an email

```bash
curl -X POST http://localhost:8080/emails \
  -H "Content-Type: application/json" \
  -H "X-API-Key: change-me-app-a" \
  -H "Idempotency-Key: order-confirmation-12345" \
  -d '{
    "to": "recipient@example.com",
    "subject": "Test",
    "html": "<p>This is a test</p>",
    "text": "This is a test",
    "priority": "normal",
    "metadata": {"userId": 123}
  }'
```

Response:

```json
{
  "id": "uuid",
  "status": "pending"
}
```

`Idempotency-Key` is optional but strongly recommended. It must be unique within the calling application. Repeating the same request with the same key returns the existing message with HTTP `200`. Reusing the key for different content returns HTTP `409`.

The SMTP `Message-ID` is derived from the queue ID and stays stable across retries. This reduces duplicate-delivery risk after an uncertain SMTP result, but SMTP cannot provide a strict exactly-once delivery guarantee.

Set `"priority": "technical"` to route a message to the separate Gmail SMTP FIFO queue. `normal` and `high` messages are never claimed by the technical worker, and the standard SMTP worker never claims `technical` messages.

### Global sender identity and branding

The sender name, reply-to address, logo, and footer are defined once in
`src/Email/EmailBrandConfig.php`. The worker applies this branding immediately before sending, so it covers
single messages, batches, retries, and technical messages without changing the original content stored in the
queue.

The actual sender email addresses remain in `.env` as `SMTP_FROM_EMAIL` and `GMAIL_FROM_EMAIL`, because SMTP
servers usually require an address authorized for the account. A mailbox avatar shown by Gmail or another email
client cannot be set in the message itself; configure the mailbox profile or domain-level BIMI separately.

### Add an attachment

Attachments are optional and intended for exceptional cases such as QR-code PNG files:

```json
{
  "to": "recipient@example.com",
  "subject": "QR code",
  "html": "<p>Your QR code is attached.</p>",
  "attachments": [
    {
      "filename": "qr.png",
      "contentBase64": "iVBORw0KGgo..."
    }
  ]
}
```

The service validates the real MIME type, stores the file under `storage/attachments`, and deletes it after the message reaches `sent` or `failed`. Multiple worker hosts must share this directory.

For images embedded in the HTML body, send the item with `inline: true` and `contentId`, then reference it with `src="cid:..."`. The inline part keeps its `filename` — Gmail will not render a `cid:` image whose MIME part has no filename, and shows a broken image plus a nameless attachment instead.

```json
{
  "to": "recipient@example.com",
  "subject": "QR code",
  "html": "<p>Your QR code:</p><img src=\"cid:participant-qr\">",
  "attachments": [
    {
      "filename": "kod-qr.png",
      "contentBase64": "iVBORw0KGgo...",
      "contentId": "participant-qr",
      "inline": true
    }
  ]
}
```

Mail clients also list inline parts in their attachment strip, so a single inline item covers both the embedded image and the downloadable file — do not send a second copy of the same image as a regular attachment, or the recipient sees it twice. Files that are only meant to be downloaded go in as regular attachments without `inline` and without `contentId`. For backward compatibility, an attachment with `contentId` and no `inline` field is still treated as inline.

### Add a batch

`POST /emails/batch` stores the common subject and body once, then creates one queue record per recipient:

```json
{
  "subject": "Newsletter",
  "html": "<p>Hello</p>",
  "priority": "normal",
  "recipients": [
    {"to": "one@example.com", "metadata": {"userId": 1}},
    {"to": "two@example.com", "metadata": {"userId": 2}}
  ]
}
```

Use `Idempotency-Key` for batch requests as well.

Batch recipients may override the common `subject`, `html`, `text`, `metadata`, and `attachments`. This is intended
for personalized messages such as participant QR-code emails where every recipient receives a different body and PNG
attachment. Top-level attachments are copied to every recipient; recipient-level attachments are stored only for that
recipient.

### Check status

```bash
curl -X GET http://localhost:8080/emails/{id} \
  -H "X-API-Key: change-me-app-a"
```

An application can read only its own messages. The `app-a` API key will not see emails added by `app-b`.

Status history is available at `GET /emails/{id}/events`. It includes queueing, processing, rate-limit releases, retries, failures, timeouts, and provider acceptance.

List endpoints (`GET /emails`, `GET /emails/unsent`) accept `limit` (default 500, max 1000) and `offset`
query parameters and return a `hasMore` flag for paging through large backlogs.

Operational diagnostics are available at `GET /emails/diagnostics`. The endpoint is authenticated per client and returns status counts, the oldest unsent message, the nearest delayed retry/rate-limit timestamp, the technical FIFO blocker, rate-limit usage, and fresh worker heartbeats.

Run retention cleanup periodically after attachments have been cleaned by workers:

```cron
15 2 * * * cd /path/to/central-mailer && /usr/bin/php scripts/cleanup-retention.php >> storage/logs/retention.log 2>&1
```

## Message statuses

- `pending` - the message is waiting in the queue.
- `processing` - the worker locked the record and is trying to send the message.
- `sent` - the configured SMTP server accepted the message. Final mailbox delivery can still fail later on the recipient side.
- `retry` - sending failed, but it will be retried after `next_attempt_at`.
- `failed` - `max_attempts` was exceeded.

Status responses also include diagnostic fields: `nextAttemptAt`, `leaseExpiresAt`, `delayReason`, `delayUntil`, `queueAgeSeconds`, `processingAgeSeconds`, `failedAttempts`, `sendAttempts`, `lastEventType`, and `lastEventAt`. `attempts` remains the number of failed attempts for compatibility; `sendAttempts` counts durable `attempt_started` events.

## Worker, retry, and ordering

The worker fetches a batch limited by `EMAIL_WORKER_BATCH_SIZE` and the rate limits. Scheduling uses:

1. effective high priority
2. weighted fairness between clients
3. `created_at ASC`

Normal messages older than `EMAIL_PRIORITY_AGING_SECONDS` receive effective high priority, so a constant stream of high-priority messages cannot starve them.

The technical worker ignores client weights and priority aging. It sends only the oldest non-terminal `technical` message. A retry scheduled for that oldest message blocks later technical messages until it is sent, permanently failed, or moved to the standard queue.

When `TECHNICAL_EMAIL_FALLBACK_TO_STANDARD=true`, a technical message that exhausts its Gmail SMTP attempts changes from `technical` to `normal`, returns to `pending`, and receives a fresh attempt budget in the standard SMTP queue. The event history records `technical_fallback` and preserves the Gmail SMTP error in `lastError`. This fallback requires a running technical worker; `/health` and `GET /emails/diagnostics` expose stale or missing technical worker heartbeats.

For MySQL/MariaDB versions that support `SELECT ... FOR UPDATE SKIP LOCKED`, the worker uses that mechanism. If the database does not support it, the fallback uses transactional `FOR UPDATE`, which is safe but can block parallel workers. Each claimed message receives a processing lease, so a delayed worker cannot overwrite a status written by a newer worker.

Retry uses exponential backoff: 60s, 120s, 240s, 480s, up to a maximum of 3600s. Worker heartbeats are stored in `email_worker_heartbeats`; `/health` returns `degraded` when it cannot see fresh standard and technical worker heartbeats.

## Gmail SMTP authorization and delivery feedback

The technical worker uses `smtp.gmail.com` with the full Gmail address in `GMAIL_SMTP_USER` and a Google App Password in `GMAIL_SMTP_APP_PASSWORD`. Do not use the normal Google account password. App Passwords require 2-Step Verification and might be unavailable for some work, school, Advanced Protection, or security-key-only accounts. OAuth 2.0 is not required for this App Password SMTP variant.

A successful SMTP send marks the queue record as `sent` and stores the stable MIME `Message-ID` as `providerMessageId`. This confirms that Gmail accepted the message for sending, not that the recipient mailbox delivered or opened it. Later rejections may arrive as bounce messages in the sender mailbox and require a separate mailbox monitoring feature. Google Workspace administrators can investigate delivery in Email Log Search, but that is an administrative troubleshooting tool and is not integrated into this API.

## Rate limit

The global limit applies to all applications together:

```dotenv
EMAIL_RATE_LIMIT_COUNT=100
EMAIL_RATE_LIMIT_WINDOW_MINUTES=15
```

Before each send attempt, the worker atomically reserves a rate-limit slot in the database. Reservations are serialized across all workers and count for the configured rolling window, including failed or uncertain SMTP attempts. If the limit is reached, the claimed message is returned to the queue with `next_attempt_at` set to the next usable window, without increasing its failed attempt count.

Each `email_clients` row can also define `rate_limit_count` and `rate_limit_window_minutes`. A client that reaches its own limit does not consume the remaining capacity of other clients.

The technical (Gmail) queue additionally honours `GMAIL_RATE_LIMIT_COUNT` / `GMAIL_RATE_LIMIT_WINDOW_MINUTES`
(default `450` per 24 h in `.example.env`, `0` disables). Gmail app-password SMTP has a hard daily cap
(~500 consumer / 2000 Workspace) and exceeding it locks the account, so keep this below the real quota.

Retries use exponential backoff (60 s doubling up to 1 h) with up to 30 s of random jitter, so messages
that failed in the same tick do not retry in one synchronized wave. Permanent SMTP rejections (5xx,
except 552) skip the retry ladder entirely and are marked `failed` after a single attempt; hard bounces
that identify a dead mailbox (`5.1.*`, `5.2.1`) also land on the suppression list automatically.

## Logging

Logs are written to:

- `storage/logs/app-YYYY-MM-DD.log`
- `storage/logs/worker-YYYY-MM-DD.log`
- `storage/logs/technical-worker-YYYY-MM-DD.log`

Technical events are logged: queued messages, idempotent replays, send attempts, success, errors, attempt count, final status, rate limiting, processing timeouts, and lease reconciliation after late provider acceptance. Persistent event history is stored in `email_events`. SMTP passwords, full HTML bodies, and metadata are not logged. `SMTP_DEBUG_LEVEL` defaults to `0`; enabling SMTP debug can expose message content and must not be used in production.

## CyberFolks/SeoHost SMTP configuration

Example for port 587 and STARTTLS:

```dotenv
SMTP_HOST=h18.seohost.pl
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USER=kontakt@example.com
SMTP_PASSWORD=mailbox-password
SMTP_FROM_EMAIL=kontakt@example.com
```

The login is the full email address, and the password is the mailbox password. `SMTP_FROM_EMAIL` should be the mailbox address or an address allowed by the hosting provider.

Alternatively, port 465 SSL:

```dotenv
SMTP_PORT=465
SMTP_SECURE=ssl
```

## Deliverability (bulk sender compliance)

Mixed traffic (marketing + transactional) must satisfy the Gmail/Yahoo bulk sender rules. The mailer
handles the message-level part; the DNS part is a one-time ops task with the highest deliverability
leverage of all.

### Message categories

Every email has a `category`: `transactional` (default for `POST /emails` and all `technical` mail)
or `marketing` (default for `POST /emails/batch`; batches can opt out with `"category": "transactional"`).
An installation that sends **only** system/transactional mail can set
`EMAIL_BATCH_DEFAULT_CATEGORY=transactional` - batches then skip the unsubscribe machinery and
`UNSUBSCRIBE_SECRET` stops being required in production.
Marketing mail automatically gets `List-Unsubscribe` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
headers once `UNSUBSCRIBE_SECRET` (32+ chars, required in production for marketing installations) is
configured; links point to
`PUBLIC_BASE_URL` (falls back to `APP_URL`) + `/unsubscribe`. The endpoint is public, idempotent and
uses stateless HMAC tokens with no expiry (links from months-old emails must keep working). Rotate the
secret via `UNSUBSCRIBE_SECRET_PREVIOUS`.

### Suppression list

`email_suppressions` blocks sending to dead or opted-out addresses. Enforced at enqueue (single email:
HTTP 422 `recipient_suppressed`; batch: suppressed recipients are inserted as `failed` without failing
the batch) and again at send time. Sources:

- automatic: hard SMTP bounces (5xx with enhanced code `5.1.*`/`5.2.1`) suppress the address globally,
- one-click / footer unsubscribe: suppresses marketing mail for that client only (invoices still go out),
- manual entries via the admin panel or `POST /admin/suppressions`.

### DNS checklist (do this once per sending domain)

1. **SPF** - the TXT record of the From domain must include the relay that sends your mail (check your
   hosting provider's docs for the exact `include:`).
2. **DKIM** - first check whether the relay already signs your From domain: send a test email to a Gmail
   account and inspect "Show original" -> `Authentication-Results` for `dkim=pass` with your domain.
   If it does not, generate a key pair, publish the public key at `<selector>._domainkey.<domain>`, keep
   the private key **outside the repo** next to `.env` (e.g. `~/.dkim/private.pem`), and enable in-app
   signing with `DKIM_ENABLED=true`, `DKIM_SELECTOR`, `DKIM_PRIVATE_KEY_PATH` (standard mailer only -
   Gmail signs its own outbound mail).
3. **DMARC** - publish `_dmarc.<domain>` starting with `p=none; rua=mailto:...`, review reports, then
   tighten to `p=quarantine`.
4. **Google Postmaster Tools** - register the domain to monitor reputation and spam-rate (must stay
   below 0.3%).

## Monitoring and alerting

`scripts/monitor-queue.php` (run from cron every 5-15 minutes) checks worker heartbeats, newly failed
emails, queue latency (oldest due-but-unsent message) and quarantined `unknown` rows. It prints a
human-readable report, exits non-zero on issues, and sends alerts through two optional channels:
`ALERT_EMAIL` (enqueued via the technical queue) and `ALERT_WEBHOOK_URL` (JSON POST, works even when
the queue itself is down). Alerts are throttled per type via `ALERT_THROTTLE_SECONDS` (default 1 h).
The workers use the same notifier for critical events (permanently failed emails, lease-lost sends).

For external uptime monitoring point the probe at `GET /health?strict=1`, which returns HTTP 503 when
worker heartbeats are stale (the plain `/health` stays 200 for the deploy gate).

```cron
*/10 * * * * cd /path/to/central-mailer-smtp/current && /usr/bin/php scripts/monitor-queue.php >> ../storage/logs/monitor.log 2>&1
```

### Async bounce processing (optional)

Most hard bounces are caught synchronously at SMTP time, but some relays accept the message and bounce
later via a DSN to the Return-Path mailbox. `scripts/process-bounces.php` polls that mailbox over IMAP
(requires PHP `ext-imap`; the script exits cleanly with a SKIP message when the extension or the
`BOUNCE_IMAP_*` variables are missing), correlates DSNs with queue rows via the deterministic
Message-ID, and suppresses hard-bounced (5.x.x) addresses globally:

```cron
*/30 * * * * cd /path/to/central-mailer-smtp/current && /usr/bin/php scripts/process-bounces.php >> ../storage/logs/bounces.log 2>&1
```

### Unknown delivery outcome (quarantine)

If a send exceeds its processing lease *after* the SMTP attempt started, the row is quarantined as
`status = unknown` instead of being retried - an automatic retry could deliver a duplicate. The common
case self-heals: when the slow send actually succeeded, the worker reconciles the row straight to `sent`.
For the rest, check `storage/logs` and the sender mailbox's Sent folder, then requeue from the admin
panel (or `POST /admin/emails/{id}/requeue`). Message-IDs are deterministic (`<queue-uuid@domain>`), so
receiving servers can deduplicate the rare duplicate that does slip through.

## Cron and systemd

The simplest cron variant starts the worker periodically. Because the current worker is a long-running
process, use `timeout` to stop it before the next minute and `flock` to prevent overlapping workers.
Set `EMAIL_WORKER_MAX_RUNTIME_SECONDS=50` in `.env` so the worker exits cleanly (finishing or releasing
its current batch) *before* `timeout` sends SIGTERM:

```cron
* * * * * cd /path/to/central-mailer-smtp/current && flock -n ../storage/worker.lock timeout 55 /usr/bin/php bin/worker.php >> ../storage/logs/cron.log 2>&1
* * * * * cd /path/to/central-mailer-smtp/current && flock -n ../storage/technical-worker.lock timeout 55 /usr/bin/php bin/technical-worker.php >> ../storage/logs/technical-cron.log 2>&1
```

On shared hosting, replace `/usr/bin/php` with the PHP CLI path provided by the host, for example
`/usr/local/php83/bin/php`. After a deploy, the next cron execution automatically uses the new application
files, so the deploy workflow does not need to restart the worker.

Set `EMAIL_WORKER_CRON_INTERVAL_SECONDS` to match the actual cron cadence (default `60`, matching the
`* * * * *` schedule above). The admin dashboard uses it to show when each worker last ran and to
compute the "next expected run" countdown, and to size the grace period before a missing heartbeat is
flagged as a critical issue (a worker sitting between two cron ticks is normal, not an outage). If your
host's cron only supports coarser granularity (e.g. every 5 minutes), set this to match — otherwise
the dashboard will alert during every normal gap between runs.

### Graceful shutdown

Workers stop cleanly (never mid-send, releasing unsent batch claims back to `pending`) on any of:

- **SIGTERM/SIGINT** — when the `pcntl` extension is available in the PHP CLI.
- **Stop file** — create `storage/worker.stop` (standard) or `storage/technical-worker.stop` (technical);
  works on any hosting, e.g. via SFTP. Remove the file to let the worker run again. Paths are configurable
  with `WORKER_STOP_FILE`.
- **Deadline** — `EMAIL_WORKER_MAX_RUNTIME_SECONDS` (0 = unlimited; recommended `50` in the cron mode above,
  leave `0` under systemd with `RuntimeMaxSec`).

On a server with systemd access, a better production variant is:

```ini
[Unit]
Description=Central Mailer Worker
After=network.target

[Service]
Type=simple
WorkingDirectory=/path/to/central-mailer-smtp/current
ExecStart=/usr/bin/php bin/worker.php
Restart=always
RestartSec=5
RuntimeMaxSec=60
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
```

After saving the file:

```bash
sudo systemctl daemon-reload
sudo systemctl enable central-mailer-worker
sudo systemctl start central-mailer-worker
```

Create a second service with `ExecStart=/usr/bin/php bin/technical-worker.php` and a distinct unit name such as
`central-mailer-technical-worker`. `RuntimeMaxSec` makes both services reload the active release shortly after a deploy.

## GitHub Actions deployment

The workflow in `.github/workflows/deploy.yml` deploys the API to the `staging` or `production` GitHub
environment. A push to `main` or `master` deploys to staging with migrations enabled. A production deploy
must be started manually with `confirm_production=DEPLOY_PRODUCTION`.
For a target database that was migrated manually or failed on duplicate columns/tables during migration adoption,
run the workflow manually with `migration_mode=adopt-existing`. That mode runs
`php scripts/run-migrations.php --adopt-existing` on the release and then continues the deploy.

Configure these secrets separately in both GitHub environments:

- `SSH_HOST`
- `SSH_PORT`
- `SSH_USER`
- `SSH_PRIVATE_KEY`
- `SSH_KNOWN_HOSTS`
- `BACKEND_REMOTE_DIR`
- `BACKEND_ALLOWED_ROOT`
- `BACKEND_BACKUP_DIR`
- `API_URL`

Before the first deployment, create the target directory, its `.env`, an empty
`.central-mailer-api-root` marker file, and shared `storage/logs` and `storage/attachments` directories. The workflow
prefers to manage `public_html` as an atomic symlink to the active release. On shared hosting, an existing
`public_html` directory is supported: the workflow publishes into it with `rsync`, preserves `.well-known`, and restores
the previous files if the health check fails. In directory mode, `public_html/index.php` loads application code through
the `current` symlink. `BACKEND_REMOTE_DIR` must be inside
`BACKEND_ALLOWED_ROOT`, while `BACKEND_BACKUP_DIR` must be outside `BACKEND_REMOTE_DIR`.
The server must provide `php`, `realpath`, `rsync`, `unzip`, `mariadb-dump` or `mysqldump`, `gzip`, and `curl`.
The workflow prefers `mariadb-dump` to avoid deprecated MariaDB compatibility aliases. If it is not available in
`PATH`, it also checks `/usr/local/mariadb-*/bin/mariadb-dump`. Set `DATABASE_DUMP_BIN` to its absolute executable
path when automatic detection is not sufficient, for example
`/usr/local/mariadb-10.11.9-EYzc/bin/mariadb-dump`. Set `APP_ENV=staging`
or `APP_ENV=production` in each target `.env` to match its GitHub environment.

The web server document root should be `public_html`, and worker services should execute
`BACKEND_REMOTE_DIR/current/bin/worker.php` and `BACKEND_REMOTE_DIR/current/bin/technical-worker.php`. The workflow
deploys into `releases`, validates configuration, runs migrations, switches `current` and `public_html` symlinks,
checks health, and rolls application symlinks back when health fails. Logs, attachments, and database backups stay
outside release directories. The `.well-known` directory is shared across releases for certificate validation.
Database backups are encrypted with AES-256 using `BACKUP_ENCRYPTION_KEY`. Old backups and releases are removed
according to retention settings.

Decrypt a backup before restore:

```bash
BACKUP_ENCRYPTION_KEY='...' openssl enc -d -aes-256-cbc -pbkdf2 -pass env:BACKUP_ENCRYPTION_KEY \
  -in predeploy-production-YYYYMMDD-HHMMSS.sql.gz.enc | gzip -dc > restore.sql
```

## Security

- API keys are stored as SHA-256 hashes in `email_clients`; the SMTP password remains in `.env`.
- A single request adds one recipient, while `/emails/batch` adds multiple recipients with shared content.
- There is no CC/BCC.
- `from` always comes from `.env`.
- The email address, priority, subject length, body size, metadata size, attachment count, attachment size, and attachment MIME type are validated.
- Applications are isolated by `sourceApp`, which is derived from the API key.
- Production startup refuses debug mode, wildcard CORS, public docs, non-HTTPS URLs, SMTP debug, weak configured API keys, and invalid SMTP encryption modes.
- Configure reverse-proxy or web-server rate limiting for invalid API-key attempts in addition to application-level per-client limits.
- Configure the web server request-body limit to no more than `APP_MAX_REQUEST_BODY_BYTES`. Apache uses
  `LimitRequestBody` from `public/.htaccess`; nginx should use `client_max_body_size`.

## How to switch SMTP to Brevo later

The sending layer is separated through `CentralMailer\Email\EmailProviderInterface`. The queue, retries, rate limiting, and statuses do not know about the specific provider.

Steps:

1. Add `src/Email/BrevoEmailProvider.php`.
2. Make the class implement `EmailProviderInterface`.
3. In `send(EmailMessage $message): EmailSendResult`, call the Brevo API and return `EmailSendResult` with the Brevo message ID if the API returns one.
4. Add `.env` variables, for example `EMAIL_PROVIDER=brevo` and `BREVO_API_KEY=...`.
5. In `public/index.php` and `bin/worker.php`, change the provider factory so that it creates `BrevoEmailProvider` for `EMAIL_PROVIDER=brevo`, and uses `SmtpEmailProvider` by default.

The rest of the application does not require changes because `EmailWorker` depends only on `EmailProviderInterface`. The queue table, retries, statuses, and rate limit stay the same.

## Troubleshooting

- `401 Invalid or missing API key`: check the `X-API-Key` header and the `API_KEY_APP_A` / `API_KEY_APP_B` values.
- `Email not found`: the message does not exist, or the API key belongs to another application.
- No sending: check whether the worker is running and whether records are in `retry` with a future `next_attempt_at`.
- Rate limit blocks sending: check the global `.env` limit, the client limit in `email_clients`, and recent rows in `email_rate_limit_reservations`.
- SMTP error: check host, port, `SMTP_SECURE`, full email login, and mailbox password.
- JSON migration issue in older MariaDB versions: make sure the database version supports the `JSON` type, or change the `metadata JSON NULL` column to `metadata LONGTEXT NULL`.
- `SQLSTATE[22003] ... queue_credit + queue_weight` on every worker run (worker exits with code 255, `POST /emails/worker/run` returns 500, queue stays `pending`): the database predates migration `011` and still has `queue_weight INT UNSIGNED`, which promotes the fair-share arithmetic to `BIGINT UNSIGNED` once `queue_credit` goes negative. Run the migrations; `011` makes the column signed and resets negative credits.
